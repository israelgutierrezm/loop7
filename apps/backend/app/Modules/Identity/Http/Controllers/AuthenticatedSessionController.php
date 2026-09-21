<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\TwoFactorService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {
    }

    public function store(LoginRequest $request): JsonResponse
    {
        $this->ensureIsNotRateLimited($request);

        $user = User::where('email', $request->string('email')->toString())->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($this->throttleKey($request));
            $this->audit->log(AuditAction::AUTH_LOGIN_FAILED, properties: [
                'email' => $request->string('email')->toString(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        // Segundo factor si el usuario lo tiene activo.
        if ($user->hasTwoFactorEnabled()) {
            $code = $request->string('code')->toString();

            if ($code === '') {
                return ApiResponse::error(
                    'Se requiere el código de verificación de dos pasos.',
                    'mfa_required',
                    status: 423,
                );
            }

            if (! $this->passesTwoFactor($user, $code)) {
                RateLimiter::hit($this->throttleKey($request));
                $this->audit->log(AuditAction::MFA_CHALLENGE_FAILED, $user, actor: $user);

                return ApiResponse::error('El código de verificación no es válido.', 'mfa_invalid', status: 422);
            }

            $this->audit->log(AuditAction::MFA_CHALLENGE_PASSED, $user, actor: $user);
        }

        RateLimiter::clear($this->throttleKey($request));

        Auth::guard('web')->login($user, $request->boolean('remember'));
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->log(AuditAction::AUTH_LOGIN, $user, actor: $user);

        return ApiResponse::success(
            new UserResource($user),
            'Sesión iniciada correctamente.',
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($user !== null) {
            $this->audit->log(AuditAction::AUTH_LOGOUT, $user, actor: $user);
        }

        return ApiResponse::message('Sesión cerrada.');
    }

    private function passesTwoFactor(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret !== null && $this->twoFactor->verify($secret, $code)) {
            return true;
        }

        return $this->twoFactor->useRecoveryCode($user, $code);
    }

    private function ensureIsNotRateLimited(LoginRequest $request): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Inténtalo de nuevo en {$seconds} segundos.",
            ]);
        }
    }

    private function throttleKey(Request $request): string
    {
        return 'login:' . mb_strtolower((string) $request->input('email')) . '|' . $request->ip();
    }
}
