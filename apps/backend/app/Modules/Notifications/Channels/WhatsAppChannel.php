<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Notifications\Services\NotificationChannels;
use App\Modules\Notifications\WhatsApp\WhatsAppClient;
use App\Modules\Notifications\WhatsApp\WhatsAppException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Aviso por WhatsApp con la plantilla de avisos de la plataforma. Un fallo no se
 * reintenta: si Meta llegó a aceptarlo, repetirlo duplicaría un mensaje con coste.
 */
class WhatsAppChannel
{
    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly NotificationChannels $channels,
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof OrganizationNotice
            || $notifiable->whatsapp_phone === null || $notifiable->whatsapp_verified_at === null) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);
        $template = $this->channels->get(NotificationChannel::WHATSAPP)->setting('notice_template');

        try {
            // Plantilla con {{1}} organización, {{2}} título y {{3}} detalle (docs/05).
            $this->client->sendTemplate($notifiable->whatsapp_phone, $template, [
                $message['organization'],
                $message['title'],
                $message['body'],
            ]);
        } catch (WhatsAppException $e) {
            // Sin el número: es un dato personal.
            Log::warning('WhatsApp: no se pudo enviar el aviso.', [
                'user_id' => $notifiable->id,
                'kind' => $notification->kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
