<?php

declare(strict_types=1);

namespace App\Modules\Reports\Listeners;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Reports\Models\ClientReport;
use App\Support\Tenancy\OrganizationScope;

/**
 * Los informes de una marca eliminada dejan de enviarse.
 */
class StopReportsOfDeletedBrand
{
    public function handle(BrandDeleted $event): void
    {
        ClientReport::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('brand_id', $event->brand->id)
            ->update(['next_send_at' => null]);
    }
}
