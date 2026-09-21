<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Servicio de MFA por TOTP (compatible con Google Authenticator, Authy, etc.).
 *
 * El secreto y los códigos de recuperación se persisten cifrados a nivel de
 * modelo (casts encrypted). El secreto nunca se devuelve al cliente tras la
 * confirmación.
 */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $engine)
    {
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * URI otpauth:// para renderizar el QR en el frontend.
     */
    public function otpauthUri(string $email, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('platform.product_name', config('app.name')),
            $email,
            $secret,
        );
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(4) . '-' . Str::random(4)))
            ->values()
            ->all();
    }

    /**
     * Consume un código de recuperación si es válido. Devuelve true si se usó.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = Str::upper(trim($code));

        if (! in_array($normalized, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_filter(
                $codes,
                fn (string $c) => $c !== $normalized,
            )),
        ])->save();

        return true;
    }
}
