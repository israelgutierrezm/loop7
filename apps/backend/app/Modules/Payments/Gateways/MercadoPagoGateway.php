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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador de Mercado Pago con suscripciones (preapproval) sin plan asociado:
 * el cliente autoriza el cobro recurrente en el `init_point` de Mercado Pago.
 * Los webhooks sólo traen ids y se validan con la firma `x-signature`
 * (HMAC-SHA256 sobre el manifest `id:…;request-id:…;ts:…;`); el estado real se
 * consulta siempre a la API. `secret_key` es el Access Token.
 */
class MercadoPagoGateway implements PaymentGatewayInterface
{
    private const API = 'https://api.mercadopago.com';

    public function key(): string
    {
        return 'mercadopago';
    }

    public function verifyWebhook(Request $request, array $credentials): bool
    {
        $secret = $credentials['webhook_secret'] ?? '';
        $header = (string) $request->header('x-signature');

        if ($secret === '' || $header === '') {
            return false;
        }

        $ts = null;
        $signature = null;
        foreach (explode(',', $header) as $segment) {
            [$k, $v] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($k === 'ts') {
                $ts = $v;
            } elseif ($k === 'v1') {
                $signature = $v;
            }
        }
        if ($ts === null || $signature === null) {
            return false;
        }

        // Manifest oficial; se omiten las partes ausentes. Ids alfanuméricos en minúsculas.
        $dataId = (string) ($request->query('data_id') ?? $request->query('data.id') ?? $request->input('data.id', ''));
        $requestId = (string) $request->header('x-request-id', '');
        $manifest = ($dataId !== '' ? 'id:' . mb_strtolower($dataId) . ';' : '')
            . ($requestId !== '' ? 'request-id:' . $requestId . ';' : '')
            . 'ts:' . $ts . ';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $signature);
    }

    public function verifyCredentials(array $credentials): void
    {
        $this->check($this->client($credentials)->get(self::API . '/users/me'), 'validar el Access Token');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = (array) $request->json()->all();
        $type = (string) ($payload['type'] ?? $request->query('type', ''));
        $dataId = (string) ($payload['data']['id'] ?? $request->query('data_id', ''));

        return new WebhookEvent(
            // Cada notificación trae su propio id; si falta, se deriva de tipo + recurso + acción.
            id: (string) ($payload['id'] ?? ($type . ':' . $dataId . ':' . ($payload['action'] ?? ''))),
            type: $type,
            data: [...$payload, 'data' => ['id' => $dataId]],
        );
    }

    public function startCheckout(CheckoutRequest $checkout, array $credentials): CheckoutResult
    {
        $preapproval = $this->check(
            $this->client($credentials)
                ->withHeaders(['X-Idempotency-Key' => 'checkout-' . $checkout->reference])
                ->post(self::API . '/preapproval', [
                    'reason' => $checkout->description(),
                    'external_reference' => $checkout->reference,
                    'payer_email' => $checkout->customerEmail,
                    'back_url' => $checkout->successUrl,
                    'status' => 'pending',
                    'auto_recurring' => [
                        'frequency' => $checkout->interval === 'year' ? 12 : 1,
                        'frequency_type' => 'months',
                        'transaction_amount' => $checkout->amount(),
                        'currency_id' => mb_strtoupper($checkout->currency),
                    ],
                ]),
            'crear la suscripción',
        );

        $url = (string) ($preapproval['init_point'] ?? '');
        if ($url === '') {
            throw new PaymentGatewayException('Mercado Pago no devolvió la URL de pago.');
        }

        return CheckoutResult::redirect($url, (string) ($preapproval['id'] ?? ''));
    }

    public function interpretWebhook(WebhookEvent $event, array $credentials): array
    {
        $id = (string) ($event->data['data']['id'] ?? '');
        if ($id === '') {
            return [];
        }

        return match ($event->type) {
            'subscription_preapproval' => $this->preapproval($id, $credentials),
            'subscription_authorized_payment' => $this->authorizedPayment($id, $credentials),
            default => [],
        };
    }

    public function cancelSubscription(string $gatewaySubscriptionId, array $credentials, bool $atPeriodEnd = false): void
    {
        // Al final del periodo la cancela la sincronización de suscripciones; aquí,
        // sólo la cancelación inmediata (cambio de plan) detiene los cobros.
        if ($atPeriodEnd) {
            return;
        }

        $this->check(
            $this->client($credentials)->put(self::API . '/preapproval/' . $gatewaySubscriptionId, ['status' => 'cancelled']),
            'cancelar la suscripción',
        );
    }

    public function resumeSubscription(string $gatewaySubscriptionId, array $credentials): void
    {
        // La cancelación al final del periodo no se aplicó aún en Mercado Pago: nada que revertir.
    }

    /**
     * @param  array<string, string>  $credentials
     * @return list<PaymentNotification>
     */
    private function preapproval(string $id, array $credentials): array
    {
        $preapproval = $this->check($this->client($credentials)->get(self::API . '/preapproval/' . $id), 'consultar la suscripción');
        $status = (string) ($preapproval['status'] ?? '');
        $recurring = (array) ($preapproval['auto_recurring'] ?? []);

        return match ($status) {
            'authorized' => [new PaymentNotification(
                type: PaymentNotification::CHECKOUT_COMPLETED,
                reference: (string) ($preapproval['external_reference'] ?? ''),
                gatewaySubscriptionId: $id,
                transactionId: 'preapproval:' . $id,
                amountCents: isset($recurring['transaction_amount']) ? (int) round((float) $recurring['transaction_amount'] * 100) : null,
                currency: isset($recurring['currency_id']) ? (string) $recurring['currency_id'] : null,
                periodEnd: isset($preapproval['next_payment_date']) ? Carbon::parse((string) $preapproval['next_payment_date']) : null,
            )],
            'cancelled' => [new PaymentNotification(type: PaymentNotification::SUBSCRIPTION_CANCELLED, gatewaySubscriptionId: $id)],
            'paused' => [new PaymentNotification(type: PaymentNotification::PAYMENT_FAILED, gatewaySubscriptionId: $id)],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $credentials
     * @return list<PaymentNotification>
     */
    private function authorizedPayment(string $id, array $credentials): array
    {
        $payment = $this->check($this->client($credentials)->get(self::API . '/authorized_payments/' . $id), 'consultar el cobro');
        $subscription = (string) ($payment['preapproval_id'] ?? '');
        if ($subscription === '') {
            return [];
        }

        $paymentStatus = (string) ($payment['payment']['status'] ?? '');
        $amount = isset($payment['transaction_amount']) ? (int) round((float) $payment['transaction_amount'] * 100) : null;
        $currency = isset($payment['currency_id']) ? (string) $payment['currency_id'] : null;

        if ($paymentStatus === 'approved') {
            return [new PaymentNotification(
                type: PaymentNotification::PAYMENT_SUCCEEDED,
                gatewaySubscriptionId: $subscription,
                transactionId: (string) ($payment['payment']['id'] ?? $id),
                amountCents: $amount,
                currency: $currency,
            )];
        }

        if (in_array($paymentStatus, ['rejected', 'cancelled'], true)) {
            return [new PaymentNotification(
                type: PaymentNotification::PAYMENT_FAILED,
                gatewaySubscriptionId: $subscription,
                transactionId: (string) ($payment['payment']['id'] ?? $id),
                amountCents: $amount,
                currency: $currency,
            )];
        }

        return []; // pendiente o en reintento: se esperará el siguiente aviso
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function client(array $credentials): PendingRequest
    {
        $token = $credentials['secret_key'] ?? '';
        if ($token === '') {
            throw new PaymentGatewayException('Falta el Access Token (secret_key) de Mercado Pago en el entorno activo.');
        }

        return Http::withToken($token)->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(Response $response, string $action): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $message = (string) ($response->json('message') ?? ('HTTP ' . $response->status()));

        throw new PaymentGatewayException('Mercado Pago no permitió ' . $action . ': ' . $message);
    }
}
