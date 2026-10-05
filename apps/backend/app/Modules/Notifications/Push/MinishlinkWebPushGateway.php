<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Web Push con minishlink/web-push (MIT): cifrado aes128gcm del contenido y
 * firma VAPID. Cliente HTTP con tiempo límite y sin redirecciones.
 */
class MinishlinkWebPushGateway implements WebPushGateway
{
    private const TIMEOUT_SECONDS = 10;

    /** Tiempo que el servicio push guarda el aviso si el navegador está apagado. */
    private const TTL_SECONDS = 86400;

    private readonly ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => self::TIMEOUT_SECONDS, 'allow_redirects' => false, 'http_errors' => false]);
    }

    public function send(array $subscriptions, string $payload, array $vapid): array
    {
        $webPush = new WebPush(
            ['VAPID' => ['subject' => $vapid['subject'], 'publicKey' => $vapid['public_key'], 'privateKey' => $vapid['private_key']]],
            ['TTL' => self::TTL_SECONDS, 'urgency' => 'normal'],
            $this->client,
            logger: app('log'),
        );

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                'aes128gcm',
            ), $payload);
        }

        $delivered = 0;
        $expired = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $delivered++;
            } elseif ($report->isSubscriptionExpired()) {
                $expired[] = $report->getEndpoint();
            } else {
                // Ni el endpoint ni el motivo (puede incluirlo): identifica al navegador del usuario.
                Log::warning('Web Push: el servicio push rechazó un aviso.', [
                    'status' => $report->getResponse()?->getStatusCode(),
                    'host' => parse_url($report->getEndpoint(), PHP_URL_HOST),
                ]);
            }
        }

        return ['delivered' => $delivered, 'expired' => $expired];
    }

    public function createKeys(): array
    {
        $keys = VAPID::createVapidKeys();

        return ['public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']];
    }
}
