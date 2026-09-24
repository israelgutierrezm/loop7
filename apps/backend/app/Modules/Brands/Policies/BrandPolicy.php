<?php

declare(strict_types=1);

namespace App\Modules\Brands\Policies;

use App\Models\User;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandAccess;
use App\Support\Tenancy\TenantContext;

/**
 * Autorización sobre Brands. Verifica permiso granular, pertenencia a la
 * Organization en contexto y acceso a la Brand concreta (Brand Access).
 */
class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BRANDS_VIEW);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->can(Permission::BRANDS_VIEW) && $this->hasBrandAccess($user, $brand);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BRANDS_CREATE);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->can(Permission::BRANDS_UPDATE) && $this->hasBrandAccess($user, $brand);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->can(Permission::BRANDS_DELETE) && $this->hasBrandAccess($user, $brand);
    }

    public function manageAccess(User $user, Brand $brand): bool
    {
        return $user->can(Permission::BRANDS_MANAGE_ACCESS) && $this->belongsToCurrentOrg($brand);
    }

    /**
     * ¿El usuario puede operar esta Brand? Requiere pertenecer a la Organization
     * en contexto y tener acceso a todas las Brands o a esta en concreto.
     */
    private function hasBrandAccess(User $user, Brand $brand): bool
    {
        return app(BrandAccess::class)->canAccess($user, $brand);
    }

    private function belongsToCurrentOrg(Brand $brand): bool
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return $organizationId !== null && $brand->organization_id === $organizationId;
    }
}
