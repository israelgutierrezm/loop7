<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use Spatie\Permission\PermissionRegistrar;

/**
 * Contexto de tenant de la request actual.
 *
 * Es el único punto de verdad sobre "qué Organization/Brand estoy operando".
 * Lo establece el middleware ResolveTenant y lo consumen:
 *  - OrganizationScope (global scope de aislamiento)
 *  - spatie/permission (team id = organization_id) para permisos por Organization
 *  - Policies y Actions
 */
final class TenantContext
{
    private ?Organization $organization = null;

    private ?Brand $brand = null;

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;

        // Alinea el "team" de spatie/permission con la Organization actual, de modo
        // que $user->can('...') se evalúe con los roles de ESA Organization.
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization?->id);

        // Cambiar de Organization invalida cualquier Brand previamente resuelta.
        if ($organization === null || ($this->brand && $this->brand->organization_id !== $organization->id)) {
            $this->brand = null;
        }
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function organizationId(): ?int
    {
        return $this->organization?->id;
    }

    public function hasOrganization(): bool
    {
        return $this->organization !== null;
    }

    public function setBrand(?Brand $brand): void
    {
        $this->brand = $brand;
    }

    public function brand(): ?Brand
    {
        return $this->brand;
    }

    public function brandId(): ?int
    {
        return $this->brand?->id;
    }

    public function hasBrand(): bool
    {
        return $this->brand !== null;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->brand = null;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
