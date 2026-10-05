<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notifications\Http\Requests\UpdateWebPushChannelRequest;
use App\Modules\Notifications\Http\Requests\UpdateWhatsAppChannelRequest;
use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Push\WebPushGateway;
use App\Modules\Notifications\Services\NotificationChannels;
use App\Modules\Notifications\WhatsApp\WhatsAppClient;
use App\Modules\Notifications\WhatsApp\WhatsAppException;
use App\Modules\Notifications\WhatsApp\WhatsAppNumber;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Canales de aviso de la plataforma desde SUPERADMIN (docs/05): claves VAPID de
 * los avisos push y número/plantillas de WhatsApp. Los secretos se cifran y
 * nunca vuelven al navegador: el token sólo se muestra enmascarado.
 */
class PlatformNotificationChannelsController extends Controller
{
    private const WHATSAPP_FIELDS = ['phone_number_id', 'notice_template', 'verification_template', 'language'];

    public function __construct(
        private readonly NotificationChannels $channels,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->present());
    }

    public function updateWebPush(UpdateWebPushChannelRequest $request): JsonResponse
    {
        $channel = $this->channels->get(NotificationChannel::WEB_PUSH);
        $enabled = (bool) $request->validated('is_enabled');

        if ($enabled && $channel->secret('private_key') === '') {
            throw ValidationException::withMessages(['is_enabled' => 'Genera las claves VAPID antes de activar los avisos push.']);
        }

        $channel->is_enabled = $enabled;
        $channel->config = [...($channel->config ?? []), 'subject' => (string) $request->validated('subject')];
        $channel->save();

        $this->audit->log(AuditAction::NOTIFICATION_CHANNEL_UPDATED, $channel, [
            'channel' => NotificationChannel::WEB_PUSH,
            'is_enabled' => $enabled,
        ]);

        return ApiResponse::success($this->present(), 'Avisos push actualizados.');
    }

    /**
     * Par de claves VAPID nuevo. Las suscripciones existentes quedan ligadas a
     * la clave anterior: se borran y cada persona vuelve a activar los avisos.
     */
    public function generatePushKeys(WebPushGateway $gateway): JsonResponse
    {
        try {
            $keys = $gateway->createKeys();
        } catch (Throwable $e) {
            Log::error('Web Push: no se pudieron generar las claves VAPID.', ['error' => $e->getMessage()]);

            // En Windows, OpenSSL necesita OPENSSL_CONF para crear claves EC (docs/05).
            return ApiResponse::error(
                'No se pudieron generar las claves: OpenSSL no puede crear claves de curva elíptica en este servidor.',
                'push_keys_failed',
                status: 422,
            );
        }
        $channel = $this->channels->get(NotificationChannel::WEB_PUSH);

        $removed = DB::transaction(function () use ($channel, $keys): int {
            $channel->config = [...($channel->config ?? []), 'public_key' => $keys['public_key']];
            $channel->credentials = ['private_key' => $keys['private_key']];
            $channel->save();

            return PushSubscription::query()->delete();
        });

        $this->audit->log(AuditAction::PUSH_KEYS_GENERATED, $channel, ['subscriptions_removed' => $removed]);

        return ApiResponse::success(
            $this->present(),
            $removed > 0
                ? "Claves generadas. Se desactivaron {$removed} navegador(es): cada persona debe volver a activar los avisos push."
                : 'Claves generadas.',
        );
    }

    public function updateWhatsApp(UpdateWhatsAppChannelRequest $request): JsonResponse
    {
        $channel = $this->channels->get(NotificationChannel::WHATSAPP);
        $data = $request->validated();

        $config = $channel->config ?? [];
        foreach (self::WHATSAPP_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $config[$field] = (string) $data[$field];
            }
        }
        $channel->config = $config;

        $tokenUpdated = is_string($data['access_token'] ?? null) && $data['access_token'] !== '';
        if ($tokenUpdated) {
            $channel->credentials = [...($channel->credentials ?? []), 'access_token' => $data['access_token']];
        }

        $channel->is_enabled = (bool) $data['is_enabled'];
        if ($channel->is_enabled && ($missing = $this->channels->whatsAppMissing($channel)) !== []) {
            throw ValidationException::withMessages(array_fill_keys($missing, 'Completa este dato para activar WhatsApp.'));
        }
        $channel->save();

        $this->audit->log(AuditAction::NOTIFICATION_CHANNEL_UPDATED, $channel, [
            'channel' => NotificationChannel::WHATSAPP,
            'is_enabled' => $channel->is_enabled,
            ...Arr::only($config, self::WHATSAPP_FIELDS),
            'token_updated' => $tokenUpdated,
        ]);

        return ApiResponse::success($this->present(), 'WhatsApp actualizado.');
    }

    /**
     * Prueba de conexión con el número emisor y, si se indica un destino, aviso
     * de prueba con la plantilla de avisos (comprueba nombre, idioma y variables).
     */
    public function testWhatsApp(Request $request, WhatsAppClient $client): JsonResponse
    {
        $data = $request->validate([
            'to' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && WhatsAppNumber::normalize($value) === null) {
                    $fail('Escribe el número con el código de país, por ejemplo +52 55 1234 5678.');
                }
            }],
        ]);

        try {
            $info = $client->phoneInfo();
        } catch (WhatsAppException $e) {
            return ApiResponse::success(['ok' => false, 'message' => $e->getMessage()]);
        }

        $message = 'Conexión correcta: ' . ($info['verified_name'] !== '' ? $info['verified_name'] : 'número') . " ({$info['display_phone_number']}).";
        $to = is_string($data['to'] ?? null) ? WhatsAppNumber::normalize($data['to']) : null;

        if ($to !== null) {
            $template = $this->channels->get(NotificationChannel::WHATSAPP)->setting('notice_template');
            try {
                $client->sendTemplate($to, $template, [
                    (string) config('app.name'),
                    'Aviso de prueba',
                    'Así llegarán los avisos por WhatsApp.',
                ]);
            } catch (WhatsAppException $e) {
                return ApiResponse::success(['ok' => false, 'message' => "{$message} Pero el aviso de prueba falló: {$e->getMessage()}"]);
            }
            $message .= ' Aviso de prueba enviado.';
        }

        return ApiResponse::success(['ok' => true, 'message' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $push = $this->channels->get(NotificationChannel::WEB_PUSH);
        $whatsApp = $this->channels->get(NotificationChannel::WHATSAPP);
        $token = $whatsApp->secret('access_token');

        return [
            'webpush' => [
                'is_enabled' => $push->is_enabled,
                'ready' => $this->channels->pushReady(),
                'public_key' => $push->setting('public_key') !== '' ? $push->setting('public_key') : null,
                'subject' => $push->setting('subject'),
                'default_subject' => $this->channels->defaultVapidSubject(),
                'subscriptions' => PushSubscription::query()->count(),
            ],
            'whatsapp' => [
                'is_enabled' => $whatsApp->is_enabled,
                'ready' => $this->channels->whatsAppReady(),
                'phone_number_id' => $whatsApp->setting('phone_number_id'),
                'notice_template' => $whatsApp->setting('notice_template'),
                'verification_template' => $whatsApp->setting('verification_template'),
                'language' => $whatsApp->setting('language') !== '' ? $whatsApp->setting('language') : 'es_MX',
                // Sólo los últimos 4 caracteres, como el resto de credenciales.
                'access_token' => $token !== '' ? '••••' . substr($token, -4) : null,
                'verified_users' => User::query()->whereNotNull('whatsapp_verified_at')->count(),
            ],
        ];
    }
}
