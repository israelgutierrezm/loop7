<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Http\Requests\SaveCustomRoleRequest;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\AccessControl\Services\CustomRoleService;
use App\Modules\AccessControl\Services\RoleCatalog;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Roles de la Organization: catálogo (predefinidos + personalizados) con los
 * que el usuario puede asignar, y gestión de los personalizados (docs/04).
 */
class RolesController extends Controller
{
    public function __construct(
        private readonly RoleCatalog $catalog,
        private readonly CustomRoleService $roles,
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(Request $request, MembershipService $memberships, EntitlementsService $entitlements): JsonResponse
    {
        $organization = $this->organization();

        return ApiResponse::success([
            'roles' => $this->catalog->all($organization),
            'assignable' => $memberships->assignableRoles($request->user(), $organization),
            'permission_groups' => $this->catalog->permissionGroups(),
            'custom_roles_available' => $entitlements->allows($organization, Entitlement::FEATURE_CUSTOM_ROLES),
        ]);
    }

    public function store(SaveCustomRoleRequest $request): JsonResponse
    {
        $data = $request->validated(); // roles.create: SaveCustomRoleRequest::authorize()

        $role = $this->roles->create(
            $this->organization(),
            $request->user(),
            $data['label'],
            $data['description'] ?? null,
            $data['permissions'],
        );

        return ApiResponse::success($this->catalog->present($role->load('permissions')), 'Rol creado.', status: 201);
    }

    public function update(SaveCustomRoleRequest $request, string $role): JsonResponse
    {
        $organization = $this->organization();
        $data = $request->validated(); // roles.update: SaveCustomRoleRequest::authorize()

        $model = $this->roles->update(
            $organization,
            $request->user(),
            $this->resolve($organization, $role),
            $data['label'],
            $data['description'] ?? null,
            $data['permissions'],
        );

        return ApiResponse::success($this->catalog->present($model), 'Rol actualizado.');
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ROLES_DELETE), 403);
        $organization = $this->organization();

        $this->roles->delete($organization, $request->user(), $this->resolve($organization, $role));

        return ApiResponse::message('Rol eliminado.');
    }

    /** Sólo roles personalizados de la organización actual (anti-IDOR). */
    private function resolve(Organization $organization, string $name): Role
    {
        return $this->catalog->findCustom($organization, $name) ?? abort(404, 'El rol no existe.');
    }

    private function organization(): Organization
    {
        return $this->tenant->organization() ?? abort(403);
    }
}
