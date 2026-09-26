<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Services;

use App\Modules\Ai\Services\EmbeddingService;
use App\Modules\Knowledge\Enums\KnowledgeDocumentStatus;
use App\Modules\Knowledge\Exceptions\DocumentExtractionException;
use App\Modules\Knowledge\Models\KnowledgeChunk;
use App\Modules\Knowledge\Models\KnowledgeDocument;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Indexa un documento del Brand Brain: extrae su texto, lo trocea, calcula los
 * vectores (si la organización tiene proveedor de embeddings) y reemplaza sus
 * fragmentos. Topes por documento y por marca para acotar lo que la búsqueda
 * recorre.
 */
final class KnowledgeIndexer
{
    public const MAX_CHUNKS_PER_DOCUMENT = 400;

    public const MAX_CHUNKS_PER_BRAND = 3000;

    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly TextChunker $chunker,
        private readonly EmbeddingService $embeddings,
    ) {
    }

    /**
     * @param  bool  $withEmbeddings  false: sólo texto (búsqueda por palabras), p. ej. si el
     *                                proveedor de embeddings no responde
     */
    public function index(KnowledgeDocument $document, bool $withEmbeddings = true): void
    {
        $text = $this->extractText($document);
        $truncated = mb_strlen($text) > DocumentTextExtractor::MAX_CHARS;
        $chunks = $this->chunker->chunk(mb_substr($text, 0, DocumentTextExtractor::MAX_CHARS));

        // Cupo de la marca: lo que ya ocupan sus otros documentos.
        $others = KnowledgeChunk::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('brand_id', $document->brand_id)
            ->where('knowledge_document_id', '!=', $document->id)
            ->count();
        $room = max(0, min(self::MAX_CHUNKS_PER_DOCUMENT, self::MAX_CHUNKS_PER_BRAND - $others));
        if (count($chunks) > $room) {
            $chunks = array_slice($chunks, 0, $room);
            $truncated = true;
        }
        if ($chunks === []) {
            throw new DocumentExtractionException('La marca alcanzó el máximo de contenido indexado: elimina documentos que ya no uses.');
        }

        $organization = Organization::query()->find($document->organization_id);
        $vectors = [];
        $spaceId = null;
        if ($withEmbeddings && $organization !== null) {
            $space = $this->embeddings->spaceFor($organization);
            if ($space !== null) {
                $vectors = $this->embeddings->embed($space, $chunks, $organization, $document->brand_id, $document->uploaded_by_user_id);
                $spaceId = $space->id();
            }
        }

        DB::transaction(function () use ($document, $chunks, $vectors, $spaceId, $truncated, $text): void {
            KnowledgeChunk::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('knowledge_document_id', $document->id)
                ->delete();

            $now = now();
            foreach (array_chunk($chunks, 200, true) as $batch) {
                $rows = [];
                foreach ($batch as $position => $content) {
                    $vector = $vectors[$position] ?? null;
                    $rows[] = [
                        'organization_id' => $document->organization_id,
                        'brand_id' => $document->brand_id,
                        'knowledge_document_id' => $document->id,
                        'position' => $position,
                        'content' => $content,
                        'embedding' => $vector !== null ? KnowledgeChunk::pack($vector) : null,
                        'embedding_space' => $vector !== null ? $spaceId : null,
                        'created_at' => $now,
                    ];
                }
                KnowledgeChunk::query()->insert($rows);
            }

            $document->forceFill([
                'status' => KnowledgeDocumentStatus::READY->value,
                'error' => null,
                'chunks_count' => count($chunks),
                'characters' => min(mb_strlen($text), DocumentTextExtractor::MAX_CHARS),
                'truncated' => $truncated,
                'embedding_space' => $vectors !== [] ? $spaceId : null,
                'indexed_at' => $now,
            ])->save();
        });
    }

    private function extractText(KnowledgeDocument $document): string
    {
        $storage = Storage::disk($document->disk);
        if (! $storage->exists($document->path)) {
            throw new DocumentExtractionException('El archivo del documento ya no existe: súbelo de nuevo.');
        }

        // Discos remotos (S3): copia temporal local para leerlo.
        if (config("filesystems.disks.{$document->disk}.driver") === 'local') {
            return $this->extractor->extract($storage->path($document->path), $document->extension);
        }

        $temp = (string) tempnam(sys_get_temp_dir(), 'kdoc');
        try {
            file_put_contents($temp, $storage->get($document->path));

            return $this->extractor->extract($temp, $document->extension);
        } finally {
            @unlink($temp);
        }
    }
}
