<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandAccess;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Miembros de la Organization: rol y acceso por marca. Reglas: nadie cambia su
 * propio rol ni su acceso; un rol sólo lo asigna quien puede asignarlo
 * (MembershipService::assignableRoles) y el acceso por marca exige
 * brands.manage_access, sin conceder marcas a las que uno mismo no accede.
 */
class MembersController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly PermissionRegistrar $registrar,
        private readonly AuditLogger $audit,
        private readonly BrandAccess $brandAccess,
    ) {
    }

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('viewMembers', $organization);

        $members = $organization->users()->orderBy('name')->get();
        $explicitBrands = $this->explicitBrands($organization, $members->pluck('id')->all());

        $payload = $members->map(fn (User $member) => $this->present($request, $organization, $member, $explicitBrands))->all();

        return ApiResponse::success($payload);
    }

    public function update(Request $request, string $user, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $member = $this->resolveMember($organization, $user);
        $actor = $request->user();

        if ($member->id === $organization->owner_user_id) {
            throw ValidationException::withMessages([
                'user' => 'No se puede modificar al propietario. Transfiere la propiedad primero.',
            ]);
        }
        if ($member->id === $actor->id) {
            throw ValidationException::withMessages([
                'user' => 'No puedes cambiar tu propio rol ni tu acceso a marcas.',
            ]);
        }

        $data = $request->validate([
            'role' => ['sometimes', 'required', 'string', Rule::in(OrganizationRole::values())],
            'all_brands_access' => ['sometimes', 'boolean'],
            'brands' => ['sometimes', 'array', 'max:500'],
            'brands.*' => ['string'],
        ]);

        if (array_key_exists('role', $data)) {
            $this->updateRole($organization, $actor, $member, $data['role']);
        }

        if (array_key_exists('all_brands_access', $data) || array_key_exists('brands', $data)) {
            $this->updateBrandAccess($organization, $actor, $member, $data);
        }

        $member = $this->resolveMember($organization, $user); // pivot actualizado

        return ApiResponse::success(
            $this->present($request, $organization, $member, $this->explicitBrands($organization, [$member->id])),
            'Miembro actualizado.',
        );
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

        DB::transaction(function () use ($organization, $member): void {
            $this->registrar->setPermissionsTeamId($organization->id);
            $member->unsetRelation('roles');
            $member->syncRoles([]);
            // Sin restos de acceso: una nueva invitación empieza de cero.
            DB::table('brand_user_access')
                ->where('user_id', $member->id)
                ->where('organization_id', $organization->id)
                ->delete();
            $organization->users()->detach($member->id);

            $this->audit->log(AuditAction::MEMBER_REMOVED, $member, ['email' => $member->email]);
        });

        return ApiResponse::message('Miembro eliminado de la organización.');
    }

    private function updateRole(Organization $organization, User $actor, User $member, string $role): void
    {
        $this->authorize('assignRole', $organization);

        $assignable = $this->memberships->assignableRoles($actor, $organization);
        $current = $this->memberships->rolesFor($member, $organization);

        // Ni asignar un rol que no puede dar, ni tocar a quien tiene uno así (un MANAGER no degrada a un ADMIN).
        if (! in_array($role, $assignable, true) || array_diff($current, $assignable) !== []) {
            abort(403, 'No puedes asignar ese rol a este miembro.');
        }

        $this->registrar->setPermissionsTeamId($organization->id);
        $member->unsetRelation('roles');
        $member->syncRoles([$role]);

        $this->audit->log(AuditAction::MEMBER_ROLE_ASSIGNED, $member, ['role' => $role]);
    }

    /**
     * @param  array{all_brands_access?: bool, brands?: list<string>}  $data
     */
    private function updateBrandAccess(Organization $organization, User $actor, User $member, array $data): void
    {
        abort_unless($actor->can(Permission::BRANDS_MANAGE_ACCESS), 403);

        // Quien está limitado a ciertas marcas sólo gestiona esas (y no concede "todas").
        $actorBrands = $this->brandAccess->restrictedBrandIds($actor);
        if ($actorBrands !== null && ($data['all_brands_access'] ?? false) === true) {
            abort(403, 'No puedes conceder acceso a todas las marcas.');
        }

        DB::transaction(function () use ($organization, $member, $data, $actorBrands): void {
            if (array_key_exists('all_brands_access', $data)) {
                $organization->users()->updateExistingPivot($member->id, ['all_brands_access' => $data['all_brands_access']]);
            }

            if (array_key_exists('brands', $data)) {
                $manageable = Brand::query()->when($actorBrands !== null, fn ($q) => $q->whereIn('id', $actorBrands ?? []));
                $wanted = (clone $manageable)->whereIn('public_id', $data['brands'])->pluck('id')->all();
                $scope = (clone $manageable)->pluck('id')->all();

                DB::table('brand_user_access')
                    ->where('user_id', $member->id)
                    ->whereIn('brand_id', array_diff($scope, $wanted))
                    ->delete();

                $existing = DB::table('brand_user_access')->where('user_id', $member->id)->pluck('brand_id')->all();
                $now = now();
                DB::table('brand_user_access')->insert(array_map(fn (int $brandId) => [
                    'brand_id' => $brandId,
                    'user_id' => $member->id,
                    'organization_id' => $organization->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_values(array_diff($wanted, $existing))));
            }

            $this->audit->log(AuditAction::MEMBER_BRAND_ACCESS_UPDATED, $member, [
                'all_brands_access' => $data['all_brands_access'] ?? null,
                'brands' => $data['brands'] ?? null,
            ]);
        });
    }

    /**
     * Marcas con acceso explícito por usuario (public_id), en una sola consulta.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, list<string>>
     */
    private function explicitBrands(Organization $organization, array $userIds): Collection
    {
        /** @var array<int, list<string>> $byUser */
        $byUser = [];
        DB::table('brand_user_access')
            ->join('brands', 'brands.id', '=', 'brand_user_access.brand_id')
            ->where('brands.organization_id', $organization->id)
            ->whereNull('brands.deleted_at')
            ->whereIn('brand_user_access.user_id', $userIds)
            ->get(['brand_user_access.user_id', 'brands.public_id'])
            ->each(function (object $row) use (&$byUser): void {
                $byUser[(int) $row->user_id][] = (string) $row->public_id;
            });

        return collect($byUser);
    }

    /**
     * @param  Collection<int, list<string>>  $explicitBrands
     * @return array<string, mixed>
     */
    private function present(Request $request, Organization $organization, User $member, Collection $explicitBrands): array
    {
        return [
            'user' => (new UserResource($member))->toArray($request),
            'membership' => [
                'status' => $member->pivot->status,
                'all_brands_access' => (bool) $member->pivot->all_brands_access,
                'brands' => $explicitBrands->get($member->id, []),
                'joined_at' => $member->pivot->joined_at,
                'is_owner' => $member->id === $organization->owner_user_id,
            ],
            'roles' => $this->memberships->rolesFor($member, $organization),
        ];
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
}
