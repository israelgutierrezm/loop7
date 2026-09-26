<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Services;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\AccessControl\Permissions\RolePermissions;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Roles disponibles en una Organization: los predefinidos (globales, docs/04)
 * y los personalizados que ella misma crea (roles de spatie con su
 * organization_id y un nombre interno `custom_…`; lo visible es la etiqueta).
 */
final class RoleCatalog
{
    public const CUSTOM_PREFIX = 'custom_';

    /** Poderes exclusivos del propietario: ningún rol personalizado los concede. */
    public const OWNER_ONLY = [
        Permission::ORGANIZATION_DELETE,
        Permission::ORGANIZATION_TRANSFER_OWNERSHIP,
    ];

    /** @var array<int, list<array{value: string, label: string, description: string|null, custom: bool, permissions: list<string>}>> */
    private array $memo = [];

    /**
     * @return list<array{value: string, label: string, description: string|null, custom: bool, permissions: list<string>}>
     */
    public function all(Organization $organization): array
    {
        return $this->memo[$organization->id] ??= $this->build($organization);
    }

    /** Tras crear, editar o eliminar un rol personalizado. */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * @return list<array{value: string, label: string, description: string|null, custom: bool, permissions: list<string>}>
     */
    private function build(Organization $organization): array
    {
        $roles = [];
        foreach (RolePermissions::map() as $name => $permissions) {
            $role = OrganizationRole::from($name);
            $roles[] = [
                'value' => $name,
                'label' => $role->label(),
                'description' => $role->description(),
                'custom' => false,
                'permissions' => $permissions,
            ];
        }

        foreach ($this->customRoles($organization) as $role) {
            $roles[] = $this->present($role);
        }

        return $roles;
    }

    /**
     * @return Collection<int, Role>
     */
    public function customRoles(Organization $organization): Collection
    {
        return Role::query()
            ->with('permissions')
            ->where('organization_id', $organization->id)
            ->where('guard_name', 'web')
            ->orderBy('label')
            ->get();
    }

    /** Rol personalizado de ESTA organización (nunca de otra). */
    public function findCustom(Organization $organization, string $name): ?Role
    {
        if (! str_starts_with($name, self::CUSTOM_PREFIX)) {
            return null;
        }

        return Role::query()
            ->with('permissions')
            ->where('organization_id', $organization->id)
            ->where('guard_name', 'web')
            ->where('name', $name)
            ->first();
    }

    /**
     * Valores de rol válidos en la organización (predefinidos + personalizados).
     *
     * @return list<string>
     */
    public function names(Organization $organization): array
    {
        return array_column($this->all($organization), 'value');
    }

    /**
     * Permisos que concede un rol de la organización (null si no existe).
     *
     * @return list<string>|null
     */
    public function permissionsOf(Organization $organization, string $name): ?array
    {
        foreach ($this->all($organization) as $role) {
            if ($role['value'] === $name) {
                return $role['permissions'];
            }
        }

        return null;
    }

    /**
     * Etiquetas visibles de una lista de roles (los desconocidos se dejan tal cual).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function labels(Organization $organization, array $names): array
    {
        $labels = array_column($this->all($organization), 'label', 'value');

        return array_map(fn (string $name) => $labels[$name] ?? $name, $names);
    }

    /**
     * Grupos de permisos con sus etiquetas, para el editor de roles.
     *
     * @return list<array{key: string, label: string, permissions: list<array{key: string, label: string, owner_only: bool}>}>
     */
    public function permissionGroups(): array
    {
        $labels = Permission::labels();
        $groupLabels = Permission::groupLabels();
        $groups = [];
        foreach (Permission::groups() as $key => $permissions) {
            $groups[] = [
                'key' => $key,
                'label' => $groupLabels[$key] ?? $key,
                'permissions' => array_map(fn (string $p) => [
                    'key' => $p,
                    'label' => $labels[$p] ?? $p,
                    'owner_only' => in_array($p, self::OWNER_ONLY, true),
                ], $permissions),
            ];
        }

        return $groups;
    }

    /**
     * @return array{value: string, label: string, description: string|null, custom: bool, permissions: list<string>}
     */
    public function present(Role $role): array
    {
        return [
            'value' => $role->name,
            'label' => (string) ($role->getAttribute('label') ?? $role->name),
            'description' => $role->getAttribute('description'),
            'custom' => true,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }
}
