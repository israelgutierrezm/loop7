<?php

declare(strict_types=1);

namespace App\Modules\Knowledge;

use App\Modules\Ai\Contracts\KnowledgeSource;
use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Knowledge\Listeners\PurgeKnowledgeOfDeletedBrand;
use App\Modules\Knowledge\Services\KnowledgeRetriever;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Base de conocimiento del Brand Brain: documentos indexados que la IA usa
 * como fuente (RAG, docs/07).
 */
class KnowledgeServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // La IA pide el contexto por su contrato; este módulo lo aporta.
        $this->app->bind(KnowledgeSource::class, KnowledgeRetriever::class);
    }

    protected function bootModule(): void
    {
        Event::listen(BrandDeleted::class, PurgeKnowledgeOfDeletedBrand::class);
    }
}
