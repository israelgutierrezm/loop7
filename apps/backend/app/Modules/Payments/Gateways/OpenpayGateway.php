<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\CheckoutRequest;
use App\Modules\Payments\Contracts\CheckoutResult;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Contracts\PaymentNotification;
use App\Modules\Payments\Contracts\WebhookEvent;
use App\Modules\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador de Openpay (BBVA) con "cargo con redirección": el cliente paga cada
 * periodo en el formulario seguro de Openpay (sin cobro automático; al vencer el
 * periodo se le pide renovar). Autenticación Basic con la llave privada como
 * usuario. Los webhooks usan Basic auth (`webhook_secret` = "usuario:contraseña")
 * y el primero trae un `verification_code` que se muestra en el registro de
 * webhooks para activarlo en el panel de Openpay.
 *
 * Credenciales: merchant_id, secret_key (llave privada), webhook_secret; ajustes:
 * country (mx, co, pe) y environment (test → sandbox).
 */
class OpenpayGateway implements PaymentGatewayInterface
{
    public function key(): string
    {
        return 'openpay';
    }

    public function verifyWebhook(Request $request, array $credentials): bool
    {
        $expected = $credentials['webhook_secret'] ?? '';
        $header = (string) $request->header('Authorization', '');

        if ($expected === '' || ! str_starts_with($header, 'Basic ')) {
            return false;
        }

        $provided = (string) base64_decode(substr($header, 6), true);

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public function verifyCredentials(array $credentials): void
    {
        // Consultar el propio comercio sólo funciona con merchant_id + llave privada válidos.
        $this->check($this->client($credentials)->get($this->baseUrl($credentials)), 'validar las credenciales');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = (array) $request->json()->all();
        $type = (string) ($payload['type'] ?? '');

        $id = $type === 'verification'
            ? 'verification:' . (string) ($payload['verification_code'] ?? '')
            : $type . ':' . (string) ($payload['transaction']['id'] ?? '');

        return new WebhookEvent(id: $id, type: $type, data: $payload);
    }

    public function startCheckout(CheckoutRequest $checkout, array $credentials): CheckoutResult
    {
        $charge = $this->check(
            $this->client($credentials)->post($this->baseUrl($credentials) . '/charges', [
                'method' => 'card',
                'amount' => $checkout->amount(),
                'currency' => mb_strtoupper($checkout->currency),
                'description' => $checkout->description(),
                'order_id' => $checkout->reference,
                'confirm' => 'false',
                'send_email' => false,
                'redirect_url' => $checkout->successUrl,
                'customer' => [
                    'name' => $checkout->customerName,
                    'email' => $checkout->customerEmail,
                ],
            ]),
            'crear el cargo',
        );

        $url = (string) ($charge['payment_method']['url'] ?? '');
        if ($url === '') {
            throw new PaymentGatewayException('Openpay no devolvió la URL de pago.');
        }

        return CheckoutResult::redirect($url, (string) ($charge['id'] ?? ''));
    }

    public function interpretWebhook(WebhookEvent $event, array $credentials): array
    {
        if ($event->type !== 'charge.succeeded') {
            return []; // verification, charge.created, charge.refunded…: sin efecto en el plan
        }

        $transaction = (array) ($event->data['transaction'] ?? []);
        $reference = (string) ($transaction['order_id'] ?? '');
        if ($reference === '' || ($transaction['status'] ?? null) !== 'completed') {
            return [];
        }

        // Cada cargo paga un periodo de la factura indicada (alta o renovación).
        return [new PaymentNotification(
            type: PaymentNotification::CHECKOUT_COMPLETED,
            reference: $reference,
            transactionId: (string) ($transaction['id'] ?? ''),
            amountCents: isset($transaction['amount']) ? (int) round((float) $transaction['amount'] * 100) : null,
            currency: isset($transaction['currency']) ? mb_strtoupper((string) $transaction['currency']) : null,
        )];
    }

    public function cancelSubscription(string $gatewaySubscriptionId, array $credentials, bool $atPeriodEnd = false): void
    {
        // Sin cobro recurrente: basta con no renovar.
    }

    public function resumeSubscription(string $gatewaySubscriptionId, array $credentials): void
    {
        // Sin cobro recurrente.
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function baseUrl(array $credentials): string
    {
        $merchant = $credentials['merchant_id'] ?? '';
        if ($merchant === '') {
            throw new PaymentGatewayException('Falta el ID de comercio (merchant_id) de Openpay en el entorno activo.');
        }

        $country = in_array($credentials['country'] ?? 'mx', ['mx', 'co', 'pe'], true) ? $credentials['country'] ?? 'mx' : 'mx';
        $host = ($credentials['environment'] ?? 'test') === 'production' ? 'api' : 'sandbox-api';

        return "https://{$host}.openpay.{$country}/v1/{$merchant}";
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function client(array $credentials): PendingRequest
    {
        $key = $credentials['secret_key'] ?? '';
        if ($key === '') {
            throw new PaymentGatewayException('Falta la llave privada (secret_key) de Openpay en el entorno activo.');
        }

        return Http::withBasicAuth($key, '')->acceptJson()->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(Response $response, string $action): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $message = (string) ($response->json('description') ?? ('HTTP ' . $response->status()));

        throw new PaymentGatewayException('Openpay no permitió ' . $action . ': ' . $message);
    }
}
