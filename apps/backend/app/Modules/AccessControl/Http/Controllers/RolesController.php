<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\AccessControl\Permissions\RolePermissions;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

class RolesController extends Controller
{
    /**
     * Catálogo de roles predefinidos y permisos (para la UI de equipo).
     */
    public function index(): JsonResponse
    {
        $roles = array_map(
            fn (OrganizationRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ],
            OrganizationRole::cases(),
        );

        return ApiResponse::success([
            'roles' => $roles,
            'permission_groups' => Permission::groups(),
            'role_permissions' => RolePermissions::map(),
        ]);
    }
}
