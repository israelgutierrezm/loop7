<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\MediaLibrary\Http\Requests\UploadMediaRequest;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\MediaLibrary\Models\MediaTag;
use App\Modules\MediaLibrary\Services\MediaService;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Biblioteca de medios de una Brand: listado con filtros (carpeta, etiqueta,
 * tipo, búsqueda), subida, organización (carpeta/etiquetas) y borrado.
 */
class MediaController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        $query = MediaAsset::query()
            ->where('brand_id', $brandModel->id)
            ->with(['folder:id,public_id,name', 'tags:id,public_id,name']);

        // Carpeta: su public_id, o "none" para lo que no está en ninguna.
        $folder = $request->string('folder')->toString();
        if ($folder === 'none') {
            $query->whereNull('folder_id');
        } elseif ($folder !== '') {
            $query->whereHas('folder', fn (Builder $q) => $q->where('public_id', $folder));
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('media_tags.public_id', $request->string('tag')->toString()));
        }

        $type = $request->string('type')->toString();
        if ($type === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($type === 'video') {
            $query->where('mime_type', 'like', 'video/%');
        } elseif ($type === 'visual') {
            $query->where(fn (Builder $q) => $q->where('mime_type', 'like', 'image/%')->orWhere('mime_type', 'like', 'video/%'));
        } elseif ($type === 'document') {
            $query->where('mime_type', 'not like', 'image/%')->where('mime_type', 'not like', 'video/%');
        }

        if ($request->filled('q')) {
            $term = addcslashes($request->string('q')->trim()->toString(), '%_\\');
            $query->where('original_name', 'like', "%{$term}%");
        }

        // Archivos concretos (p. ej. los ya elegidos en el selector).
        if ($request->filled('ids')) {
            $query->whereIn('public_id', array_slice(explode(',', $request->string('ids')->toString()), 0, 30));
        }

        $perPage = min(60, max(1, (int) $request->integer('per_page', 30)));
        $assets = $query->latest()->latest('id')
            ->paginate($perPage)
            ->through(fn (MediaAsset $a) => $this->present($a));

        return ApiResponse::paginated($assets);
    }

    public function store(UploadMediaRequest $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('content.create'), 403);

        $file = $request->file('file');
        $folderId = $this->media->folderId($brandModel->id, $request->filled('folder') ? (string) $request->input('folder') : null);

        // Límite de almacenamiento del plan.
        $organization = $brandModel->organization;
        if ($organization !== null) {
            $this->media->ensureStorageAvailable($organization, (int) $file->getSize());
        }

        $asset = $this->media->upload($brandModel, $file, $folderId, $request->user());
        $this->audit->log(AuditAction::MEDIA_UPLOADED, $asset, [
            'brand' => $brandModel->public_id,
            'name' => $asset->original_name,
        ]);

        return ApiResponse::success($this->present($asset->load(['folder', 'tags'])), 'Archivo subido.', status: 201);
    }

    /**
     * Organizar: mover a una carpeta y/o cambiar sus etiquetas.
     */
    public function update(Request $request, string $asset): JsonResponse
    {
        $model = $this->resolveAsset($asset);
        abort_unless($request->user()->can('content.update'), 403);

        $data = $request->validate([
            'folder' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
        ]);

        if (array_key_exists('folder', $data)) {
            $model->forceFill(['folder_id' => $this->media->folderId($model->brand_id, $data['folder'])])->save();
        }
        if (array_key_exists('tags', $data)) {
            $this->media->syncTags($model, $data['tags']);
        }

        $this->audit->log(AuditAction::MEDIA_UPDATED, $model, ['changes' => array_keys($data)]);

        return ApiResponse::success($this->present($model->fresh(['folder', 'tags']) ?? $model), 'Archivo actualizado.');
    }

    public function destroy(Request $request, string $asset): JsonResponse
    {
        $model = $this->resolveAsset($asset);
        abort_unless($request->user()->can('content.delete'), 403);

        $this->audit->log(AuditAction::MEDIA_DELETED, $model, ['name' => $model->original_name]);
        $this->media->delete($model);

        return ApiResponse::message('Archivo eliminado.');
    }

    /**
     * Etiquetas de la biblioteca de la marca, con cuántos archivos las usan.
     */
    public function tags(string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        $tags = MediaTag::query()
            ->where('brand_id', $brandModel->id)
            ->withCount('assets')
            ->orderBy('name')
            ->get()
            ->map(fn (MediaTag $t) => ['id' => $t->public_id, 'name' => $t->name, 'count' => $t->assets_count])
            ->all();

        return ApiResponse::success($tags);
    }

    private function resolveAsset(string $publicId): MediaAsset
    {
        $model = MediaAsset::query()->with('brand')->where('public_id', $publicId)->firstOrFail();
        $this->authorizeBrand($model->brand);

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MediaAsset $asset): array
    {
        return [
            'id' => $asset->public_id,
            'original_name' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'extension' => $asset->extension,
            'size_bytes' => $asset->size_bytes,
            'width' => $asset->width,
            'height' => $asset->height,
            'is_image' => $asset->isImage(),
            'is_video' => $asset->isVideo(),
            'folder' => $asset->relationLoaded('folder') && $asset->folder !== null
                ? ['id' => $asset->folder->public_id, 'name' => $asset->folder->name]
                : null,
            'tags' => $asset->relationLoaded('tags')
                ? $asset->tags->map(fn (MediaTag $t) => ['id' => $t->public_id, 'name' => $t->name])->values()->all()
                : [],
            'url' => $this->media->temporaryUrl($asset),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}
