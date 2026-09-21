<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Services;

use App\Models\User;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

class MembershipService
{
    public function __construct(private readonly PermissionRegistrar $registrar)
    {
    }

    /**
     * Organizations activas del usuario, cada una con los nombres de rol del
     * usuario en ESA Organization (atributo dinámico current_roles).
     *
     * @return Collection<int, Organization>
     */
    public function organizationsWithRoles(User $user): Collection
    {
        $previousTeam = $this->registrar->getPermissionsTeamId();

        $organizations = $user->organizations()
            ->wherePivot('status', 'active')
            ->orderBy('organizations.name')
            ->get();

        foreach ($organizations as $organization) {
            $organization->current_roles = $this->rolesFor($user, $organization);
        }

        $this->registrar->setPermissionsTeamId($previousTeam);

        return $organizations;
    }

    /**
     * @return list<string>
     */
    public function rolesFor(User $user, Organization $organization): array
    {
        $this->registrar->setPermissionsTeamId($organization->id);
        $user->unsetRelation('roles');

        return $user->getRoleNames()->values()->all();
    }

    /**
     * Permisos efectivos del usuario en una Organization.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user, Organization $organization): array
    {
        $this->registrar->setPermissionsTeamId($organization->id);
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }
}
