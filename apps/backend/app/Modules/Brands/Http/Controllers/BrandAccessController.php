<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Models\Brand;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BrandAccessController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {
    }

    public function index(string $brand): JsonResponse
    {
        $model = $this->resolveBrand($brand);
        $this->authorize('manageAccess', $model);

        $users = $model->usersWithAccess()->orderBy('name')->get()
            ->map(fn (User $u) => ['id' => $u->public_id, 'name' => $u->name, 'email' => $u->email])
            ->all();

        return ApiResponse::success($users);
    }

    public function grant(Request $request, string $brand): JsonResponse
    {
        $model = $this->resolveBrand($brand);
        $this->authorize('manageAccess', $model);

        $data = $request->validate(['user_id' => ['required', 'string']]);
        $member = $this->resolveMember($data['user_id']);

        $model->grantAccessTo($member->id);
        $this->audit->log(AuditAction::BRAND_ACCESS_GRANTED, $model, ['user' => $member->public_id]);

        return ApiResponse::message('Acceso a la marca concedido.');
    }

    public function revoke(string $brand, string $user): JsonResponse
    {
        $model = $this->resolveBrand($brand);
        $this->authorize('manageAccess', $model);

        $member = $this->resolveMember($user);
        $model->usersWithAccess()->detach($member->id);
        $this->audit->log(AuditAction::BRAND_ACCESS_REVOKED, $model, ['user' => $member->public_id]);

        return ApiResponse::message('Acceso a la marca revocado.');
    }

    private function resolveBrand(string $publicId): Brand
    {
        return Brand::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function resolveMember(string $publicId): User
    {
        $organization = $this->context->organization();

        /** @var User|null $member */
        $member = $organization?->users()->where('users.public_id', $publicId)->first();

        if ($member === null) {
            throw ValidationException::withMessages([
                'user_id' => 'El usuario no pertenece a esta organización.',
            ]);
        }

        return $member;
    }
}
