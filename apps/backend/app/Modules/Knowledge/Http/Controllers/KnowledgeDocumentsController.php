<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Ai\Services\EmbeddingService;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Knowledge\Enums\KnowledgeDocumentStatus;
use App\Modules\Knowledge\Http\Requests\UploadKnowledgeDocumentRequest;
use App\Modules\Knowledge\Jobs\IndexKnowledgeDocument;
use App\Modules\Knowledge\Models\KnowledgeDocument;
use App\Modules\Knowledge\Services\DocumentTextExtractor;
use App\Modules\Knowledge\Services\KnowledgeRetriever;
use App\Modules\Organizations\Models\Organization;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos del Brand Brain de una marca (RAG, docs/07): subir, listar,
 * reindexar, descargar, eliminar y probar qué fragmentos encontraría la IA.
 * Leer exige acceso a la marca; modificar, además, brands.update.
 */
class KnowledgeDocumentsController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementsService $entitlements,
        private readonly EmbeddingService $embeddings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $organization = $this->organization();
        $space = $this->embeddings->spaceFor($organization);

        $documents = KnowledgeDocument::query()
            ->where('brand_id', $brandModel->id)
            ->with('uploader:id,name')
            ->latest()
            ->latest('id')
            ->get();

        return ApiResponse::success([
            'documents' => $documents->map(fn (KnowledgeDocument $d) => $this->present($d))->all(),
            'used' => KnowledgeDocument::query()->count(),
            'limit' => $this->entitlements->limit($organization, Entitlement::KNOWLEDGE_DOCUMENTS_MAX),
            'semantic' => $space !== null && $space->isSemantic(),
            'max_size_mb' => (int) (UploadKnowledgeDocumentRequest::MAX_KB / 1024),
            'extensions' => DocumentTextExtractor::EXTENSIONS,
        ]);
    }

    public function store(UploadKnowledgeDocumentRequest $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can(Permission::BRANDS_UPDATE), 403);
        $organization = $this->organization();

        if (! $this->entitlements->withinLimit($organization, Entitlement::KNOWLEDGE_DOCUMENTS_MAX, KnowledgeDocument::query()->count())) {
            throw new PlanLimitExceededException(
                'Alcanzaste el número de documentos del Brand Brain incluidos en tu plan.',
                Entitlement::KNOWLEDGE_DOCUMENTS_MAX,
            );
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $disk = (string) config('filesystems.default');
        $path = $file->storeAs(
            "knowledge/{$organization->id}/{$brandModel->id}",
            Str::lower((string) Str::ulid()) . '.' . $extension,
            $disk,
        );
        abort_if($path === false, 500, 'No se pudo guardar el archivo.');

        $originalName = Str::limit($file->getClientOriginalName(), 250, '');
        $title = trim((string) $request->input('title', ''));

        $document = KnowledgeDocument::query()->create([
            'organization_id' => $organization->id,
            'brand_id' => $brandModel->id,
            'title' => Str::limit($title !== '' ? $title : pathinfo($originalName, PATHINFO_FILENAME), 200, ''),
            'original_name' => $originalName,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'extension' => $extension,
            'size_bytes' => (int) $file->getSize(),
            'status' => KnowledgeDocumentStatus::PENDING->value,
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        $this->audit->log(AuditAction::KNOWLEDGE_DOCUMENT_UPLOADED, $document, ['title' => $document->title, 'brand' => $brandModel->public_id]);
        IndexKnowledgeDocument::dispatch($document->id);

        return ApiResponse::success($this->present($document->fresh(['uploader']) ?? $document), 'Documento subido: se está indexando.', status: 201);
    }

    public function reindex(Request $request, string $brand, string $document): JsonResponse
    {
        $model = $this->resolveDocument($this->resolveBrand($brand), $document);
        abort_unless($request->user()->can(Permission::BRANDS_UPDATE), 403);

        $model->forceFill(['status' => KnowledgeDocumentStatus::PENDING->value, 'error' => null])->save();
        IndexKnowledgeDocument::dispatch($model->id);

        return ApiResponse::success($this->present($model->fresh(['uploader']) ?? $model), 'Reindexando el documento.');
    }

    public function destroy(Request $request, string $brand, string $document): JsonResponse
    {
        $model = $this->resolveDocument($this->resolveBrand($brand), $document);
        abort_unless($request->user()->can(Permission::BRANDS_UPDATE), 403);

        $this->audit->log(AuditAction::KNOWLEDGE_DOCUMENT_DELETED, $model, ['title' => $model->title]);
        $model->delete(); // sus fragmentos caen por la clave foránea
        Storage::disk($model->disk)->delete($model->path);

        return ApiResponse::message('Documento eliminado: la IA ya no lo usará.');
    }

    public function download(string $brand, string $document): StreamedResponse
    {
        $model = $this->resolveDocument($this->resolveBrand($brand), $document);
        abort_unless(Storage::disk($model->disk)->exists($model->path), 404, 'El archivo ya no existe.');

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }

    /**
     * Probador: qué fragmentos encontraría la IA para una pregunta.
     */
    public function search(Request $request, string $brand, KnowledgeRetriever $retriever): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:300']]);

        $results = array_map(fn (array $r) => [
            ...$r,
            'content' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $r['content'])), 400),
        ], $retriever->search($brandModel, $data['q'], 5));

        return ApiResponse::success($results);
    }

    /** Documento de ESA marca (anti-IDOR: nunca por id global). */
    private function resolveDocument(Brand $brand, string $publicId): KnowledgeDocument
    {
        return KnowledgeDocument::query()
            ->where('brand_id', $brand->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function organization(): Organization
    {
        return $this->tenant->organization() ?? abort(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(KnowledgeDocument $document): array
    {
        return [
            'id' => $document->public_id,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'extension' => $document->extension,
            'size_bytes' => $document->size_bytes,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'error' => $document->error,
            'chunks_count' => $document->chunks_count,
            'characters' => $document->characters,
            'truncated' => $document->truncated,
            'semantic' => $document->embedding_space !== null && ! str_starts_with($document->embedding_space, 'fake:'),
            'uploaded_by' => $document->uploader?->name,
            'indexed_at' => $document->indexed_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
