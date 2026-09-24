<?php

declare(strict_types=1);

namespace App\Modules\Brands\Services;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Support\Tenancy\TenantContext;

/**
 * Brand Access dentro de la Organization en contexto: un miembro tiene acceso
 * a todas las Brands (`all_brands_access`) o sólo a las asignadas en
 * brand_user_access. Única fuente de esta regla para policies, controladores
 * y consultas agregadas.
 */
class BrandAccess
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function hasAllBrands(User $user): bool
    {
        $organization = $this->context->organization();

        return $organization !== null && $organization->users()
            ->where('users.id', $user->id)
            ->wherePivot('all_brands_access', true)
            ->exists();
    }

    public function canAccess(User $user, Brand $brand): bool
    {
        $organizationId = $this->context->organizationId();

        if ($organizationId === null || $brand->organization_id !== $organizationId) {
            return false;
        }

        return $this->hasAllBrands($user)
            || $brand->usersWithAccess()->where('users.id', $user->id)->exists();
    }

    /**
     * IDs de las Brands a las que se limita el usuario, o null si accede a
     * todas (así sólo se filtra con whereIn cuando hace falta).
     *
     * @return list<int>|null
     */
    public function restrictedBrandIds(User $user): ?array
    {
        if ($this->hasAllBrands($user)) {
            return null;
        }

        return $user->accessibleBrands()
            ->where('brands.organization_id', $this->context->organizationId())
            ->pluck('brands.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
