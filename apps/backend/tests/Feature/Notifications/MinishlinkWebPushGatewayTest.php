<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Push\MinishlinkWebPushGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * La librería real (cifrado aes128gcm y firma VAPID) contra un servicio push
 * simulado: sin red.
 */
class MinishlinkWebPushGatewayTest extends TestCase
{
    protected bool $seed = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (@openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) === false) {
            $this->markTestSkipped('OpenSSL no puede crear claves EC en este sistema (en Windows, define OPENSSL_CONF).');
        }
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    /**
     * Suscripción como la de un navegador: clave P-256 propia y secreto de 16 bytes.
     */
    private function subscription(string $endpoint): PushSubscription
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key);
        $ec = openssl_pkey_get_details($key)['ec'];
        $point = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return new PushSubscription([
            'endpoint' => $endpoint,
            'public_key' => self::b64($point),
            'auth_token' => self::b64(random_bytes(16)),
        ]);
    }

    public function test_cifra_firma_y_detecta_suscripciones_caducadas(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(410)]));
        $stack->push(Middleware::history($history));
        $gateway = new MinishlinkWebPushGateway(new Client(['handler' => $stack, 'http_errors' => false]));

        $keys = $gateway->createKeys();
        $this->assertSame(65, strlen(self::decode($keys['public_key'])));
        $this->assertSame(32, strlen(self::decode($keys['private_key'])));

        $result = $gateway->send(
            [$this->subscription('https://fcm.googleapis.com/fcm/send/vivo'), $this->subscription('https://fcm.googleapis.com/fcm/send/caducado')],
            '{"title":"Contenido por aprobar"}',
            [...$keys, 'subject' => 'mailto:ops@loop7.test'],
        );

        $this->assertSame(['delivered' => 1, 'expired' => ['https://fcm.googleapis.com/fcm/send/caducado']], $result);

        $request = $history[0]['request'];
        $this->assertSame('https://fcm.googleapis.com/fcm/send/vivo', (string) $request->getUri());
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('86400', $request->getHeaderLine('TTL'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('k=' . $keys['public_key'], $request->getHeaderLine('Authorization'));
        // El contenido viaja cifrado para ese navegador.
        $this->assertStringNotContainsString('aprobar', (string) $request->getBody());
    }
}
