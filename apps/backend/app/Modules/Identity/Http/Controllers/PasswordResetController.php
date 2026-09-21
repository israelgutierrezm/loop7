<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Solicita el enlace de restablecimiento. Responde siempre igual para no
     * revelar si el correo existe (anti-enumeración).
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return ApiResponse::message('Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña.');
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
                $this->audit->log(AuditAction::AUTH_PASSWORD_RESET, $user, actor: $user);
            },
        );

        if ($status !== Password::PasswordReset) {
            return ApiResponse::error('El enlace de restablecimiento no es válido o expiró.', 'invalid_reset_token', status: 422);
        }

        return ApiResponse::message('Tu contraseña fue restablecida. Ya puedes iniciar sesión.');
    }
}
