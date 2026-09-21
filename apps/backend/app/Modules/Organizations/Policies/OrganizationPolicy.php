<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Policies;

use App\Models\User;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Organizations\Models\Organization;

/**
 * Autorización sobre Organizations. Combina:
 *  - pertenencia del usuario a la Organization,
 *  - permiso granular (evaluado en el team de la Organization actual).
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // el listado se acota por membresía en la query
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization)
            && $user->can(Permission::ORGANIZATION_UPDATE);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $organization->owner_user_id === $user->id
            && $user->can(Permission::ORGANIZATION_DELETE);
    }

    public function transferOwnership(User $user, Organization $organization): bool
    {
        // Sólo el OWNER real puede transferir la propiedad.
        return $organization->owner_user_id === $user->id;
    }

    public function viewMembers(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization)
            && $user->can(Permission::MEMBERS_VIEW);
    }

    public function invite(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization)
            && $user->can(Permission::MEMBERS_INVITE);
    }

    public function updateMember(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization)
            && $user->can(Permission::MEMBERS_UPDATE);
    }

    public function removeMember(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization)
            && $user->can(Permission::MEMBERS_REMOVE);
    }

    public function assignRole(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization)
            && $user->can(Permission::ROLES_ASSIGN);
    }
}
