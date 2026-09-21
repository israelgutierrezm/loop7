<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class MembersController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly PermissionRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('viewMembers', $organization);

        $members = $organization->users()->orderBy('name')->get();

        $payload = $members->map(fn (User $member) => [
            'user' => (new UserResource($member))->toArray($request),
            'membership' => [
                'status' => $member->pivot->status,
                'all_brands_access' => (bool) $member->pivot->all_brands_access,
                'joined_at' => $member->pivot->joined_at,
                'is_owner' => $member->id === $organization->owner_user_id,
            ],
            'roles' => $this->memberships->rolesFor($member, $organization),
        ])->all();

        return ApiResponse::success($payload);
    }

    public function update(Request $request, string $user, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('updateMember', $organization);

        $member = $this->resolveMember($organization, $user);

        if ($member->id === $organization->owner_user_id) {
            throw ValidationException::withMessages([
                'user' => 'No se puede modificar el rol del propietario. Transfiere la propiedad primero.',
            ]);
        }

        $data = $request->validate([
            'role' => ['sometimes', 'required', 'string', Rule::in($this->assignableRoles())],
            'all_brands_access' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('role', $data)) {
            $this->authorize('assignRole', $organization);
            $this->registrar->setPermissionsTeamId($organization->id);
            $member->unsetRelation('roles');
            $member->syncRoles([$data['role']]);

            $this->audit->log(AuditAction::MEMBER_ROLE_ASSIGNED, $member, ['role' => $data['role']]);
        }

        if (array_key_exists('all_brands_access', $data)) {
            $organization->users()->updateExistingPivot($member->id, [
                'all_brands_access' => $data['all_brands_access'],
            ]);
        }

        return ApiResponse::success([
            'user' => (new UserResource($member))->toArray($request),
            'roles' => $this->memberships->rolesFor($member, $organization),
        ], 'Miembro actualizado.');
    }

    public function destroy(Request $request, string $user, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('removeMember', $organization);

        $member = $this->resolveMember($organization, $user);

        if ($member->id === $organization->owner_user_id) {
            throw ValidationException::withMessages([
                'user' => 'No se puede eliminar al propietario de la organización.',
            ]);
        }

        $this->registrar->setPermissionsTeamId($organization->id);
        $member->unsetRelation('roles');
        $member->syncRoles([]);
        $organization->users()->detach($member->id);

        $this->audit->log(AuditAction::MEMBER_REMOVED, $member, ['email' => $member->email]);

        return ApiResponse::message('Miembro eliminado de la organización.');
    }

    private function resolveMember(Organization $organization, string $publicId): User
    {
        /** @var User|null $member */
        $member = $organization->users()->where('users.public_id', $publicId)->first();

        if ($member === null) {
            throw ValidationException::withMessages([
                'user' => 'El miembro no existe en esta organización.',
            ]);
        }

        return $member;
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(): array
    {
        // OWNER se gestiona por transferencia de propiedad, no por asignación directa.
        return array_values(array_filter(
            OrganizationRole::values(),
            fn (string $r) => $r !== OrganizationRole::OWNER->value,
        ));
    }
}
