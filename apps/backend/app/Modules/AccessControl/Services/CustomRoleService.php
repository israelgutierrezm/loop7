<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Enums\InvitationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles personalizados de una Organization (docs/04). Reglas:
 * - sólo con la función de plan feature.custom_roles (crear y editar);
 * - nadie concede permisos que no tiene, ni los exclusivos del propietario;
 * - sólo se editan/eliminan roles cuyos permisos ya tiene quien actúa, y nunca
 *   el rol que uno mismo tiene;
 * - no se elimina un rol en uso (miembros o invitaciones pendientes).
 */
final class CustomRoleService
{
    public const MAX_PER_ORGANIZATION = 50;

    public function __construct(
        private readonly RoleCatalog $catalog,
        private readonly MembershipService $memberships,
        private readonly EntitlementsService $entitlements,
        private readonly PermissionRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  list<string>  $permissions
     */
    public function create(Organization $organization, User $actor, string $label, ?string $description, array $permissions): Role
    {
        $this->assertPlanAllows($organization);
        if ($this->catalog->customRoles($organization)->count() >= self::MAX_PER_ORGANIZATION) {
            throw ValidationException::withMessages(['label' => 'Alcanzaste el máximo de ' . self::MAX_PER_ORGANIZATION . ' roles personalizados.']);
        }
        $label = $this->assertLabelAvailable($organization, $label);
        $permissions = $this->assertGrantable($organization, $actor, $permissions);

        $role = DB::transaction(function () use ($organization, $label, $description, $permissions): Role {
            $this->registrar->setPermissionsTeamId($organization->id);
            /** @var Role $role */
            $role = Role::query()->create([
                'name' => RoleCatalog::CUSTOM_PREFIX . Str::lower((string) Str::ulid()),
                'guard_name' => 'web',
                'organization_id' => $organization->id,
                'label' => $label,
                'description' => $description,
            ]);
            $role->syncPermissions($permissions);

            return $role;
        });

        $this->changed();
        $this->audit->log(AuditAction::ROLE_CREATED, $role, ['label' => $label, 'permissions' => $permissions]);

        return $role;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function update(Organization $organization, User $actor, Role $role, string $label, ?string $description, array $permissions): Role
    {
        $this->assertPlanAllows($organization);
        $this->assertManageable($organization, $actor, $role);
        $label = $this->assertLabelAvailable($organization, $label, $role);
        $permissions = $this->assertGrantable($organization, $actor, $permissions);
        $before = $role->permissions->pluck('name')->all();

        DB::transaction(function () use ($organization, $role, $label, $description, $permissions): void {
            $this->registrar->setPermissionsTeamId($organization->id);
            $role->forceFill(['label' => $label, 'description' => $description])->save();
            $role->syncPermissions($permissions);
        });

        $this->changed();
        $this->audit->log(AuditAction::ROLE_UPDATED, $role, [
            'label' => $label,
            'added' => array_values(array_diff($permissions, $before)),
            'removed' => array_values(array_diff($before, $permissions)),
        ]);

        return $role->refresh()->load('permissions');
    }

    public function delete(Organization $organization, User $actor, Role $role): void
    {
        $this->assertManageable($organization, $actor, $role);

        $members = DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('role_id', $role->id)
            ->where('organization_id', $organization->id)
            ->count();
        $invitations = OrganizationInvitation::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->where('role', $role->name)
            ->where('status', InvitationStatus::PENDING->value)
            ->count();
        if ($members > 0 || $invitations > 0) {
            abort(409, "Este rol está en uso ({$members} miembro(s), {$invitations} invitación(es) pendiente(s)): cámbiales el rol antes de eliminarlo.");
        }

        $label = (string) $role->getAttribute('label');
        $role->delete();
        $this->changed();
        $this->audit->log(AuditAction::ROLE_DELETED, null, ['role' => $role->name, 'label' => $label]);
    }

    private function assertPlanAllows(Organization $organization): void
    {
        if (! $this->entitlements->allows($organization, Entitlement::FEATURE_CUSTOM_ROLES)) {
            throw new PlanLimitExceededException(
                'Los roles personalizados están disponibles en los planes Professional, Agency y Enterprise.',
                Entitlement::FEATURE_CUSTOM_ROLES,
            );
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function assertGrantable(Organization $organization, User $actor, array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        $reserved = array_intersect($permissions, RoleCatalog::OWNER_ONLY);
        if ($reserved !== []) {
            throw ValidationException::withMessages(['permissions' => 'Eliminar o transferir la organización es exclusivo del propietario.']);
        }

        $missing = array_diff($permissions, $this->memberships->permissionsFor($actor, $organization));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'No puedes conceder permisos que no tienes: ' . implode(', ', $missing) . '.',
            ]);
        }

        return $permissions;
    }

    private function assertManageable(Organization $organization, User $actor, Role $role): void
    {
        if (in_array($role->name, $this->memberships->rolesFor($actor, $organization), true)) {
            abort(403, 'No puedes modificar el rol que tienes asignado.');
        }
        $missing = array_diff($role->permissions->pluck('name')->all(), $this->memberships->permissionsFor($actor, $organization));
        if ($missing !== []) {
            abort(403, 'Este rol concede permisos que no tienes: no puedes modificarlo.');
        }
    }

    private function assertLabelAvailable(Organization $organization, string $label, ?Role $except = null): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        $normalized = Str::lower($label);

        $predefined = array_map(
            fn (OrganizationRole $r) => [Str::lower($r->value), Str::lower($r->label())],
            OrganizationRole::cases(),
        );
        if (in_array($normalized, array_merge(...$predefined), true)) {
            throw ValidationException::withMessages(['label' => 'Ese nombre es el de un rol predefinido: elige otro.']);
        }

        $taken = $this->catalog->customRoles($organization)
            ->reject(fn (Role $r) => $except !== null && $r->id === $except->id)
            ->contains(fn (Role $r) => Str::lower((string) $r->getAttribute('label')) === $normalized);
        if ($taken) {
            throw ValidationException::withMessages(['label' => 'Ya existe un rol con ese nombre.']);
        }

        return $label;
    }

    private function changed(): void
    {
        $this->registrar->forgetCachedPermissions();
        $this->catalog->flush();
    }
}
