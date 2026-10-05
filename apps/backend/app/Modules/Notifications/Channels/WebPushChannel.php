<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Models\User;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Notifications\Services\WebPushSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aviso push en los navegadores del usuario. Un fallo no se reintenta: el aviso
 * ya está en la app y repetir el envío podría duplicarlo en los que sí llegó.
 */
class WebPushChannel
{
    public function __construct(private readonly WebPushSender $sender)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof OrganizationNotice) {
            return;
        }

        try {
            $this->sender->send($notifiable, $notification->toWebPush($notifiable));
        } catch (Throwable $e) {
            Log::warning('Web Push: no se pudo enviar el aviso.', [
                'user_id' => $notifiable->id,
                'kind' => $notification->kind,
                'error' => $e::class,
            ]);
        }
    }
}
