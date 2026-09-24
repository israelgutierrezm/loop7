<?php

declare(strict_types=1);

namespace App\Modules\Content\Listeners;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;

/**
 * Nada de una marca eliminada debe publicarse: se cancelan sus publicaciones
 * pendientes y el contenido programado.
 */
class CancelPublicationsOfDeletedBrand
{
    public function handle(BrandDeleted $event): void
    {
        $contentIds = ContentItem::query()->withoutGlobalScopes()
            ->where('brand_id', $event->brand->id)
            ->select('id');

        PublicationTarget::query()->withoutGlobalScopes()
            ->whereIn('post_variant_id', PostVariant::query()->withoutGlobalScopes()
                ->whereIn('content_item_id', $contentIds)
                ->select('id'))
            ->whereIn('status', [TargetStatus::PENDING->value, TargetStatus::SCHEDULED->value])
            ->update(['status' => TargetStatus::CANCELLED->value, 'error' => 'La marca se eliminó.']);

        ContentItem::query()->withoutGlobalScopes()
            ->where('brand_id', $event->brand->id)
            ->where('status', ContentStatus::SCHEDULED->value)
            ->update(['status' => ContentStatus::CANCELLED->value]);
    }
}
