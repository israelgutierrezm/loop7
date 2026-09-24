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
 * Adaptador de Stripe: Checkout alojado en modo suscripción (precio en línea,
 * sin catálogo previo en Stripe), webhooks firmados (HMAC-SHA256 sobre
 * "timestamp.payload" con tolerancia de 5 min) y cancelación/reanudación.
 */
class StripeGateway implements PaymentGatewayInterface
{
    private const API = 'https://api.stripe.com/v1';

    /** Tolerancia de la marca de tiempo de la firma (anti-replay). */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function key(): string
    {
        return 'stripe';
    }

    public function verifyWebhook(Request $request, array $credentials): bool
    {
        $secret = $credentials['webhook_secret'] ?? '';
        $header = (string) $request->header('Stripe-Signature');

        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $segment) {
            [$k, $v] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1' && $v !== null) {
                $signatures[] = $v;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function verifyCredentials(array $credentials): void
    {
        $this->check($this->client($credentials)->get(self::API . '/balance'), 'validar la clave secreta');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = (array) $request->json()->all();

        return new WebhookEvent(
            id: (string) ($payload['id'] ?? ''),
            type: (string) ($payload['type'] ?? ''),
            data: $payload,
        );
    }

    public function startCheckout(CheckoutRequest $checkout, array $credentials): CheckoutResult
    {
        $session = $this->check(
            $this->client($credentials)
                ->withHeaders(['Idempotency-Key' => 'checkout-' . $checkout->reference])
                ->asForm()
                ->post(self::API . '/checkout/sessions', [
                    'mode' => 'subscription',
                    'success_url' => $checkout->successUrl,
                    'cancel_url' => $checkout->cancelUrl,
                    'client_reference_id' => $checkout->reference,
                    'customer_email' => $checkout->customerEmail,
                    'line_items' => [[
                        'quantity' => 1,
                        'price_data' => [
                            'currency' => mb_strtolower($checkout->currency),
                            'unit_amount' => $checkout->amountCents,
                            'recurring' => ['interval' => $checkout->interval],
                            'product_data' => ['name' => $checkout->description()],
                        ],
                    ]],
                    'metadata' => ['reference' => $checkout->reference, 'organization' => $checkout->organizationId],
                    'subscription_data' => [
                        'metadata' => ['reference' => $checkout->reference, 'organization' => $checkout->organizationId],
                    ],
                ]),
            'crear la sesión de pago',
        );

        $url = (string) ($session['url'] ?? '');
        if ($url === '') {
            throw new PaymentGatewayException('Stripe no devolvió la URL de pago.');
        }

        return CheckoutResult::redirect($url, (string) ($session['id'] ?? ''));
    }

    public function interpretWebhook(WebhookEvent $event, array $credentials): array
    {
        $object = (array) ($event->data['data']['object'] ?? []);

        return match ($event->type) {
            'checkout.session.completed' => $this->checkoutCompleted($object),
            'invoice.paid' => $this->invoicePaid($object),
            'invoice.payment_failed' => [new PaymentNotification(
                type: PaymentNotification::PAYMENT_FAILED,
                gatewaySubscriptionId: $this->invoiceSubscription($object),
                transactionId: isset($object['id']) ? (string) $object['id'] : null,
                amountCents: isset($object['amount_due']) ? (int) $object['amount_due'] : null,
                currency: isset($object['currency']) ? mb_strtoupper((string) $object['currency']) : null,
            )],
            'customer.subscription.deleted' => [new PaymentNotification(
                type: PaymentNotification::SUBSCRIPTION_CANCELLED,
                gatewaySubscriptionId: isset($object['id']) ? (string) $object['id'] : null,
            )],
            default => [],
        };
    }

    public function cancelSubscription(string $gatewaySubscriptionId, array $credentials, bool $atPeriodEnd = false): void
    {
        $client = $this->client($credentials);
        $url = self::API . '/subscriptions/' . $gatewaySubscriptionId;

        $this->check(
            $atPeriodEnd ? $client->asForm()->post($url, ['cancel_at_period_end' => 'true']) : $client->delete($url),
            'cancelar la suscripción',
        );
    }

    public function resumeSubscription(string $gatewaySubscriptionId, array $credentials): void
    {
        $this->check(
            $this->client($credentials)->asForm()->post(self::API . '/subscriptions/' . $gatewaySubscriptionId, [
                'cancel_at_period_end' => 'false',
            ]),
            'reanudar la suscripción',
        );
    }

    /**
     * @param  array<string, mixed>  $session
     * @return list<PaymentNotification>
     */
    private function checkoutCompleted(array $session): array
    {
        $paid = in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true);
        $reference = (string) ($session['client_reference_id'] ?? $session['metadata']['reference'] ?? '');

        if (($session['mode'] ?? null) !== 'subscription' || ! $paid || $reference === '') {
            return [];
        }

        return [new PaymentNotification(
            type: PaymentNotification::CHECKOUT_COMPLETED,
            reference: $reference,
            gatewaySubscriptionId: isset($session['subscription']) ? (string) $session['subscription'] : null,
            transactionId: (string) ($session['invoice'] ?? $session['id'] ?? ''),
            amountCents: isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            currency: isset($session['currency']) ? mb_strtoupper((string) $session['currency']) : null,
        )];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return list<PaymentNotification>
     */
    private function invoicePaid(array $invoice): array
    {
        // El primer cobro ya lo activa checkout.session.completed.
        if (($invoice['billing_reason'] ?? null) === 'subscription_create') {
            return [];
        }

        $subscription = $this->invoiceSubscription($invoice);
        if ($subscription === null) {
            return [];
        }

        $periodEnd = $invoice['lines']['data'][0]['period']['end'] ?? null;

        return [new PaymentNotification(
            type: PaymentNotification::PAYMENT_SUCCEEDED,
            gatewaySubscriptionId: $subscription,
            transactionId: isset($invoice['id']) ? (string) $invoice['id'] : null,
            amountCents: isset($invoice['amount_paid']) ? (int) $invoice['amount_paid'] : null,
            currency: isset($invoice['currency']) ? mb_strtoupper((string) $invoice['currency']) : null,
            periodEnd: is_numeric($periodEnd) ? Carbon::createFromTimestamp((int) $periodEnd) : null,
        )];
    }

    /**
     * La suscripción de una factura (campo clásico o, en API recientes, parent.subscription_details).
     *
     * @param  array<string, mixed>  $invoice
     */
    private function invoiceSubscription(array $invoice): ?string
    {
        $id = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function client(array $credentials): PendingRequest
    {
        $secret = $credentials['secret_key'] ?? '';
        if ($secret === '') {
            throw new PaymentGatewayException('Falta la clave secreta (secret_key) de Stripe en el entorno activo.');
        }

        return Http::withToken($secret)->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(Response $response, string $action): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $message = (string) ($response->json('error.message') ?? ('HTTP ' . $response->status()));

        throw new PaymentGatewayException('Stripe no permitió ' . $action . ': ' . $message);
    }
}
