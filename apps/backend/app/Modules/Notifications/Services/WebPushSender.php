<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Push\PushEndpoint;
use App\Modules\Notifications\Push\WebPushGateway;
use Illuminate\Support\Str;

/**
 * Envía un aviso push a todos los navegadores en los que el usuario lo activó
 * y olvida los que el servicio push da por desaparecidos.
 */
class WebPushSender
{
    /** El contenido cifrado no puede pasar de ~4 KB: se recorta el texto. */
    private const MAX_TITLE = 100;

    private const MAX_BODY = 500;

    public function __construct(
        private readonly WebPushGateway $gateway,
        private readonly NotificationChannels $channels,
    ) {
    }

    /**
     * @param  array{title: string, body: string, path?: string|null, tag?: string|null}  $message
     * @return int navegadores que aceptaron el aviso
     */
    public function send(User $user, array $message): int
    {
        if (! $this->channels->pushReady()) {
            return 0;
        }

        // El endpoint se validó al guardarlo; se revisa otra vez por si la lista cambió.
        $subscriptions = $user->pushSubscriptions()->get()
            ->filter(fn (PushSubscription $s): bool => PushEndpoint::allowed($s->endpoint))
            ->values()
            ->all();
        if ($subscriptions === []) {
            return 0;
        }

        $result = $this->gateway->send($subscriptions, $this->payload($message), $this->channels->vapid());

        $expired = array_map(fn (string $endpoint): string => PushSubscription::hashEndpoint($endpoint), $result['expired']);
        if ($expired !== []) {
            $user->pushSubscriptions()->whereIn('endpoint_hash', $expired)->delete();
        }
        if ($result['delivered'] > 0) {
            $user->pushSubscriptions()->whereNotIn('endpoint_hash', $expired)->update(['last_used_at' => now()]);
        }

        return $result['delivered'];
    }

    /**
     * Lo que recibe el service worker del SPA (public/sw.js). La ruta es relativa:
     * el service worker la abre en su propio origen.
     *
     * @param  array{title: string, body: string, path?: string|null, tag?: string|null}  $message
     */
    private function payload(array $message): string
    {
        return json_encode([
            'title' => Str::limit($message['title'], self::MAX_TITLE),
            'body' => Str::limit($message['body'], self::MAX_BODY),
            'path' => $message['path'] ?? null,
            'tag' => $message['tag'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
