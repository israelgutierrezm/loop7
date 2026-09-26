<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Listeners;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Knowledge\Models\KnowledgeDocument;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Los documentos de una marca eliminada se borran de verdad (archivo y
 * fragmentos): pueden contener información sensible del negocio. Los archivos
 * se borran tras confirmar la transacción del borrado de la marca.
 */
class PurgeKnowledgeOfDeletedBrand
{
    public function handle(BrandDeleted $event): void
    {
        $documents = KnowledgeDocument::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('brand_id', $event->brand->id)
            ->get(['id', 'disk', 'path']);

        foreach ($documents as $document) {
            $document->delete(); // fragmentos por clave foránea
            DB::afterCommit(fn () => Storage::disk($document->disk)->delete($document->path));
        }
    }
}
