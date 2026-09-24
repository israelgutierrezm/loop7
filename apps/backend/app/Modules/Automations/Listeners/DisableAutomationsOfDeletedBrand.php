<?php

declare(strict_types=1);

namespace App\Modules\Automations\Listeners;

use App\Modules\Automations\Models\Automation;
use App\Modules\Brands\Events\BrandDeleted;

/**
 * Las automatizaciones acotadas a una marca eliminada se pausan.
 */
class DisableAutomationsOfDeletedBrand
{
    public function handle(BrandDeleted $event): void
    {
        Automation::query()->withoutGlobalScopes()
            ->where('brand_id', $event->brand->id)
            ->update(['is_enabled' => false]);
    }
}
