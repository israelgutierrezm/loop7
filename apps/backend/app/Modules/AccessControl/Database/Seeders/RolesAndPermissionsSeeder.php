<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Database\Seeders;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\AccessControl\Permissions\RolePermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea permisos y roles predefinidos como definiciones GLOBALES (team_id null),
 * compartidas por todas las Organizations. La asignación a usuarios sí queda
 * acotada por Organization (pivot model_has_roles.organization_id).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Roles/permisos definidos a nivel global (sin team).
        $registrar->setPermissionsTeamId(null);

        foreach (Permission::all() as $permission) {
            SpatiePermission::findOrCreate($permission, 'web');
        }

        foreach (RolePermissions::map() as $roleName => $permissions) {
            $role = SpatieRole::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }

        $registrar->forgetCachedPermissions();
    }
}
