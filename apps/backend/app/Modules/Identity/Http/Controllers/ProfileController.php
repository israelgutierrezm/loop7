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
use App\Support\Security\ImpersonationSession;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'impersonation' => ImpersonationSession::active($request)
                ? ['active' => true, 'expires_in_minutes' => ImpersonationSession::TTL_MINUTES]
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

        // Las demás sesiones guardan el hash anterior: AuthenticateSession las cierra.
        return ApiResponse::message('Contraseña actualizada. Las demás sesiones se cerraron.');
    }

    /**
     * Cierra la sesión en todos los demás dispositivos (p. ej. tras usar un equipo
     * ajeno). Rehace el hash de la contraseña: AuthenticateSession (Sanctum)
     * invalida las sesiones que guardan el anterior; la actual se actualiza.
     */
    public function logoutOtherSessions(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']], [
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $user = $request->user();
        $guard = Auth::guard('web');
        abort_unless($guard instanceof SessionGuard, 409, 'Esta acción requiere una sesión web.');
        $guard->logoutOtherDevices($request->string('password')->toString());
        if ($request->hasSession()) {
            $request->session()->put('password_hash_web', $user->fresh()?->getAuthPassword());
        }

        $this->audit->log(AuditAction::AUTH_OTHER_SESSIONS_REVOKED, $user, actor: $user);

        return ApiResponse::message('Se cerró la sesión en los demás dispositivos.');
    }
}
