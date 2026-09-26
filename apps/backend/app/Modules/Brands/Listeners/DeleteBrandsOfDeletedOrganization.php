<?php

declare(strict_types=1);

namespace App\Modules\Brands\Listeners;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Support\Tenancy\OrganizationScope;

/**
 * Las marcas de una organización eliminada se eliminan con ella. BrandDeleted
 * reutiliza la limpieza de cada módulo: publicaciones canceladas, cuentas
 * sociales desconectadas (sin tokens) y automatizaciones pausadas.
 */
class DeleteBrandsOfDeletedOrganization
{
    public function handle(OrganizationDeleted $event): void
    {
        Brand::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $event->organization->id)
            ->each(function (Brand $brand): void {
                $brand->delete();
                BrandDeleted::dispatch($brand);
            });
    }
}
