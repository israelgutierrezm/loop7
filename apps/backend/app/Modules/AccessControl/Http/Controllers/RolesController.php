<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\AccessControl\Permissions\RolePermissions;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolesController extends Controller
{
    /**
     * Catálogo de roles predefinidos y permisos (para la UI de equipo), con los
     * roles que el usuario actual puede asignar.
     */
    public function index(Request $request, TenantContext $tenant, MembershipService $memberships): JsonResponse
    {
        $roles = array_map(
            fn (OrganizationRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ],
            OrganizationRole::cases(),
        );

        $organization = $tenant->organization();

        return ApiResponse::success([
            'roles' => $roles,
            'assignable' => $organization !== null ? $memberships->assignableRoles($request->user(), $organization) : [],
            'permission_groups' => Permission::groups(),
            'role_permissions' => RolePermissions::map(),
        ]);
    }
}
