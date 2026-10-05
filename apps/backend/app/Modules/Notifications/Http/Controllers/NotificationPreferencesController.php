<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Enums\DeliveryChannel;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Modules\Notifications\Services\NotificationChannels;
use App\Modules\Notifications\Services\NotificationPreferences;
use App\Modules\Notifications\WhatsApp\WhatsAppNumber;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Qué avisos recibe el usuario además en la app, y por qué canal: correo, push
 * del navegador o WhatsApp.
 */
class NotificationPreferencesController extends Controller
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly NotificationChannels $channels,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payload($request->user()));
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $this->preferences->update($request->user(), $request->choices());

        return ApiResponse::success($this->payload($request->user()), 'Preferencias guardadas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        $settings = $this->preferences->settings($user);
        $pushReady = $this->channels->pushReady();

        return [
            'channels' => [
                DeliveryChannel::MAIL->value => ['label' => DeliveryChannel::MAIL->label(), 'available' => true],
                DeliveryChannel::PUSH->value => [
                    'label' => DeliveryChannel::PUSH->label(),
                    'available' => $pushReady,
                    // Clave pública VAPID para PushManager.subscribe().
                    'public_key' => $pushReady ? $this->channels->vapid()['public_key'] : null,
                    'devices' => $user->pushSubscriptions()->count(),
                ],
                DeliveryChannel::WHATSAPP->value => [
                    'label' => DeliveryChannel::WHATSAPP->label(),
                    'available' => $this->channels->whatsAppAvailableFor($user),
                    'phone' => $user->whatsapp_phone !== null && $user->whatsapp_verified_at !== null
                        ? WhatsAppNumber::mask($user->whatsapp_phone)
                        : null,
                ],
            ],
            'categories' => array_map(fn (NotificationCategory $c) => [
                'key' => $c->value,
                'label' => $c->label(),
                'description' => $c->description(),
                ...$settings[$c->value],
            ], NotificationCategory::cases()),
        ];
    }
}
