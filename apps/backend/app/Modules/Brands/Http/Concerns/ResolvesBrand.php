<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Concerns;

use App\Modules\Brands\Models\Brand;

/**
 * Resuelve una Brand por public_id dentro de la Organization actual y verifica
 * el acceso del usuario (BrandPolicy). El OrganizationScope evita el acceso
 * cruzado entre Organizations (anti-IDOR).
 */
trait ResolvesBrand
{
    protected function resolveBrand(string $publicId): Brand
    {
        /** @var Brand $brand */
        $brand = Brand::query()->where('public_id', $publicId)->firstOrFail();
        $this->authorize('view', $brand);

        return $brand;
    }
}
