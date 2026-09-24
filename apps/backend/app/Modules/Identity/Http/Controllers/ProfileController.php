<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Organizations\Http\Resources\OrganizationResource;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function show(Request $request, MembershipService $memberships): JsonResponse
    {
        $user = $request->user();
        $organizations = $memberships->organizationsWithRoles($user);

        return ApiResponse::success([
            'user' => (new UserResource($user))->toArray($request),
            'organizations' => $organizations
                ->map(fn ($org) => (new OrganizationResource($org))->toArray($request))
                ->all(),
            'impersonation' => $request->session()->has('impersonator_id')
                ? ['active' => true]
                : null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'locale' => ['sometimes', 'required', 'string', 'in:es,en'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],
        ]);

        $user = $request->user();
        $user->fill($data)->save();

        return ApiResponse::success(new UserResource($user), 'Perfil actualizado.');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ], [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->string('password')->toString())])->save();

        $this->audit->log(AuditAction::AUTH_PASSWORD_CHANGED, $user, actor: $user);

        return ApiResponse::message('Contraseña actualizada.');
    }
}
