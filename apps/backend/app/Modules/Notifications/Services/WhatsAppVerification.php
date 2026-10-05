<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Notifications\WhatsApp\WhatsAppClient;
use App\Modules\Notifications\WhatsApp\WhatsAppException;
use App\Modules\Notifications\WhatsApp\WhatsAppNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Verificación del número de WhatsApp de un usuario con un código de un solo
 * uso: así nadie hace que la plataforma escriba a un número ajeno. El número
 * no se guarda hasta confirmarlo y el código sólo se conserva como HMAC.
 */
class WhatsAppVerification
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    /** Cada código cuesta un mensaje: límites diarios por usuario y por número. */
    private const DAILY_CODES_PER_USER = 10;

    private const DAILY_CODES_PER_PHONE = 5;

    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly NotificationChannels $channels,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Envía el código al número.
     *
     * @throws ValidationException si se superó el límite diario
     * @throws WhatsAppException si WhatsApp rechazó el envío
     */
    public function start(User $user, string $phone): void
    {
        $userKey = "whatsapp-code:user:{$user->id}";
        $phoneKey = 'whatsapp-code:phone:' . hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($userKey, self::DAILY_CODES_PER_USER)
            || RateLimiter::tooManyAttempts($phoneKey, self::DAILY_CODES_PER_PHONE)) {
            throw ValidationException::withMessages(['phone' => 'Pediste demasiados códigos hoy. Inténtalo mañana.']);
        }

        $code = (string) random_int(100000, 999999);
        $template = $this->channels->get(NotificationChannel::WHATSAPP)->setting('verification_template');

        // Las plantillas de autenticación llevan el código en el cuerpo y en el botón «Copiar código».
        $this->client->sendTemplate($phone, $template, [$code], $code);

        RateLimiter::hit($userKey, 86400);
        RateLimiter::hit($phoneKey, 86400);

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        Cache::put($this->key($user), [
            'phone' => Crypt::encryptString($phone),
            'hash' => $this->hash($user, $code),
        ], $expiresAt);
        Cache::put($this->attemptsKey($user), 0, $expiresAt);
    }

    /**
     * Comprueba el código y, si es correcto, guarda el número como verificado.
     *
     * @throws ValidationException
     */
    public function confirm(User $user, string $code): string
    {
        $pending = Cache::get($this->key($user));
        if (! is_array($pending) || ! is_string($pending['hash'] ?? null) || ! is_string($pending['phone'] ?? null)) {
            throw ValidationException::withMessages(['code' => 'El código caducó o no existe. Pide uno nuevo.']);
        }

        // Incremento atómico: varias peticiones a la vez no suman intentos de más.
        if ((int) Cache::increment($this->attemptsKey($user)) > self::MAX_ATTEMPTS) {
            $this->forget($user);

            throw ValidationException::withMessages(['code' => 'Demasiados intentos. Pide un código nuevo.']);
        }

        if (! hash_equals($pending['hash'], $this->hash($user, $code))) {
            throw ValidationException::withMessages(['code' => 'El código no es correcto.']);
        }

        $this->forget($user);
        $phone = Crypt::decryptString($pending['phone']);
        $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_verified_at' => now()])->save();

        $this->audit->log(AuditAction::NOTIFICATIONS_WHATSAPP_VERIFIED, $user, ['phone' => WhatsAppNumber::mask($phone)]);

        return $phone;
    }

    public function remove(User $user): void
    {
        $this->forget($user);
        if ($user->whatsapp_phone === null) {
            return;
        }

        $masked = WhatsAppNumber::mask($user->whatsapp_phone);
        $user->forceFill(['whatsapp_phone' => null, 'whatsapp_verified_at' => null])->save();

        $this->audit->log(AuditAction::NOTIFICATIONS_WHATSAPP_REMOVED, $user, ['phone' => $masked]);
    }

    private function forget(User $user): void
    {
        Cache::forget($this->key($user));
        Cache::forget($this->attemptsKey($user));
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id . '|' . $code, (string) config('app.key'));
    }

    private function key(User $user): string
    {
        return "whatsapp-verification:{$user->id}";
    }

    private function attemptsKey(User $user): string
    {
        return "whatsapp-verification-attempts:{$user->id}";
    }
}
