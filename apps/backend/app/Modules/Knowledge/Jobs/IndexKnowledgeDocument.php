<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Jobs;

use App\Modules\Knowledge\Enums\KnowledgeDocumentStatus;
use App\Modules\Knowledge\Exceptions\DocumentExtractionException;
use App\Modules\Knowledge\Models\KnowledgeDocument;
use App\Modules\Knowledge\Services\KnowledgeIndexer;
use App\Support\Security\SecretRedactor;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Indexa un documento del Brand Brain en segundo plano. Un documento ilegible
 * falla al momento (no tiene arreglo reintentando); si el proveedor de
 * embeddings no responde, el último intento indexa sin vectores para que al
 * menos funcione la búsqueda por palabras clave.
 */
class IndexKnowledgeDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('default');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('knowledge-document-' . $this->documentId))->releaseAfter(30)];
    }

    public function handle(KnowledgeIndexer $indexer): void
    {
        $document = KnowledgeDocument::query()->withoutGlobalScope(OrganizationScope::class)->find($this->documentId);
        if ($document === null) {
            return; // se eliminó mientras esperaba en la cola
        }

        $document->forceFill(['status' => KnowledgeDocumentStatus::PROCESSING->value, 'error' => null])->save();

        try {
            $indexer->index($document);
        } catch (DocumentExtractionException $e) {
            $document->forceFill(['status' => KnowledgeDocumentStatus::FAILED->value, 'error' => Str::limit($e->getMessage(), 500)])->save();
        } catch (Throwable $e) {
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
            Log::warning('Base de conocimiento: indexado sin vectores.', ['document' => $document->id, 'error' => SecretRedactor::redact($e->getMessage())]);
            $indexer->index($document, withEmbeddings: false);
            $document->forceFill(['error' => 'Indexado sin búsqueda semántica: el proveedor de IA no respondió. Reindexa más tarde.'])->save();
        }
    }

    public function failed(Throwable $exception): void
    {
        KnowledgeDocument::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($this->documentId)
            ->update(['status' => KnowledgeDocumentStatus::FAILED->value, 'error' => 'No se pudo indexar el documento. Inténtalo de nuevo.']);
    }
}
