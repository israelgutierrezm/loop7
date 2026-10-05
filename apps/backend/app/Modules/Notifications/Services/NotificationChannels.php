<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;

/**
 * Estado de los canales de aviso configurables (push del navegador y WhatsApp):
 * qué está listo para enviar y con qué datos.
 */
class NotificationChannels
{
    public function __construct(private readonly EntitlementsService $entitlements)
    {
    }

    public function get(string $key): NotificationChannel
    {
        return NotificationChannel::query()->firstOrNew(['key' => $key], ['is_enabled' => false, 'config' => []]);
    }

    /**
     * Push habilitado y con claves VAPID.
     */
    public function pushReady(): bool
    {
        $channel = $this->get(NotificationChannel::WEB_PUSH);

        return $channel->is_enabled && $channel->setting('public_key') !== '' && $channel->secret('private_key') !== '';
    }

    /**
     * @return array{public_key: string, private_key: string, subject: string}
     */
    public function vapid(): array
    {
        $channel = $this->get(NotificationChannel::WEB_PUSH);

        return [
            'public_key' => $channel->setting('public_key'),
            'private_key' => $channel->secret('private_key'),
            // El servicio push contacta al operador si hay problemas (RFC 8292).
            'subject' => $channel->setting('subject') !== '' ? $channel->setting('subject') : $this->defaultVapidSubject(),
        ];
    }

    public function defaultVapidSubject(): string
    {
        return 'mailto:' . (string) config('mail.from.address');
    }

    /**
     * WhatsApp habilitado, con número, token y las dos plantillas (avisos y
     * código de verificación).
     */
    public function whatsAppReady(): bool
    {
        $channel = $this->get(NotificationChannel::WHATSAPP);

        return $channel->is_enabled && $this->whatsAppMissing($channel) === [];
    }

    /**
     * Datos que faltan para poder activar WhatsApp.
     *
     * @return list<string>
     */
    public function whatsAppMissing(NotificationChannel $channel): array
    {
        $missing = [];
        foreach (['phone_number_id', 'notice_template', 'verification_template'] as $setting) {
            if ($channel->setting($setting) === '') {
                $missing[] = $setting;
            }
        }
        if ($channel->secret('access_token') === '') {
            $missing[] = 'access_token';
        }

        return $missing;
    }

    /**
     * WhatsApp listo y el plan de la organización lo incluye (cada mensaje tiene coste).
     */
    public function whatsAppAllowedFor(?Organization $organization): bool
    {
        return $organization !== null
            && $this->whatsAppReady()
            && $this->entitlements->allows($organization, Entitlement::FEATURE_WHATSAPP_NOTIFICATIONS);
    }

    /**
     * ¿Puede el usuario registrar su número? Basta con que una de sus
     * organizaciones lo incluya; los avisos de las demás no salen por WhatsApp.
     */
    public function whatsAppAvailableFor(User $user): bool
    {
        if (! $this->whatsAppReady()) {
            return false;
        }

        return $user->organizations()->wherePivot('status', MembershipStatus::ACTIVE->value)->get()
            ->contains(fn (Organization $organization): bool => $this->entitlements->allows($organization, Entitlement::FEATURE_WHATSAPP_NOTIFICATIONS));
    }
}
