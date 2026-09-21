<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\TwoFactorService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Inicia el alta de MFA: genera secreto + códigos de recuperación (sin
     * confirmar aún). Devuelve el secreto y el otpauth URI SÓLO en este paso.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return ApiResponse::error('El doble factor ya está activado.', 'mfa_already_enabled', status: 409);
        }

        $secret = $this->twoFactor->generateSecret();
        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => null,
        ])->save();

        return ApiResponse::success([
            'secret' => $secret,
            'otpauth_uri' => $this->twoFactor->otpauthUri($user->email, $secret),
            'recovery_codes' => $recoveryCodes,
        ], 'Escanea el código QR y confirma con un código para activar el doble factor.');
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();
        $secret = $user->two_factor_secret;

        if ($secret === null) {
            return ApiResponse::error('Primero debes iniciar la activación del doble factor.', 'mfa_not_started', status: 409);
        }

        if (! $this->twoFactor->verify($secret, $request->string('code')->toString())) {
            return ApiResponse::error('El código no es válido.', 'mfa_invalid', status: 422);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->audit->log(AuditAction::MFA_ENABLED, $user, actor: $user);

        return ApiResponse::success([
            'recovery_codes' => $user->two_factor_recovery_codes,
        ], 'Doble factor activado.');
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']], [
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->log(AuditAction::MFA_DISABLED, $user, actor: $user);

        return ApiResponse::message('Doble factor desactivado.');
    }
}
