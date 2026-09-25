<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * Roles que el usuario puede asignar (al invitar o cambiar un rol) en la
     * Organization. OWNER nunca se asigna (se transfiere); ADMIN sólo lo asigna
     * quien administra miembros (OWNER/ADMIN), para que un rol inferior con
     * permiso de invitar o asignar roles no pueda crear administradores.
     *
     * @return list<string>
     */
    public function assignableRoles(User $actor, Organization $organization): array
    {
        $canManageMembers = in_array(Permission::MEMBERS_UPDATE, $this->permissionsFor($actor, $organization), true);

        return array_values(array_filter(
            OrganizationRole::values(),
            fn (string $role): bool => $role !== OrganizationRole::OWNER->value
                && ($canManageMembers || $role !== OrganizationRole::ADMIN->value),
        ));
    }

    /**
     * Miembros activos que tienen un permiso en la Organization y, si se indica
     * una Brand, acceso a ella (Brand Access). En una sola consulta: sirve para
     * destinatarios de avisos o para elegir a quién asignar trabajo.
     *
     * @return Collection<int, User>
     */
    public function membersWithPermission(Organization $organization, string $permission, ?int $brandId = null): Collection
    {
        $previousTeam = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($organization->id);

        try {
            return $this->activeMembersQuery($organization, $brandId)
                ->permission($permission)
                ->orderBy('name')
                ->get();
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * De los usuarios indicados, los que siguen siendo miembros activos (y con
     * acceso a la Brand, si se indica).
     *
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    public function activeMembers(Organization $organization, array $userIds, ?int $brandId = null): Collection
    {
        if ($userIds === []) {
            return new Collection();
        }

        return $this->activeMembersQuery($organization, $brandId)
            ->whereIn('users.id', $userIds)
            ->get();
    }

    /**
     * @return Builder<User>
     */
    private function activeMembersQuery(Organization $organization, ?int $brandId): Builder
    {
        return User::query()
            ->whereIn('users.id', DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->select('user_id'))
            ->when($brandId !== null, fn (Builder $query) => $query->where(fn (Builder $access) => $access
                ->whereIn('users.id', DB::table('organization_user')
                    ->where('organization_id', $organization->id)
                    ->where('all_brands_access', true)
                    ->select('user_id'))
                ->orWhereIn('users.id', DB::table('brand_user_access')
                    ->where('brand_id', $brandId)
                    ->select('user_id'))));
    }
}
