<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Billing\Models\Plan;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Contracts\CheckoutResult;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Contracts\WebhookEvent;
use App\Modules\Payments\Exceptions\GatewayNotImplementedException;
use Illuminate\Http\Request;

/**
 * Adaptador de Stripe. La verificación de firma de webhook es real (HMAC-SHA256
 * sobre "timestamp.payload", como en la cabecera Stripe-Signature). El checkout
 * vía API queda pendiente (requiere credenciales y cuenta reales) — MVP docs/20.
 */
class StripeGateway implements PaymentGatewayInterface
{
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

        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, null);
            if ($k !== null && $v !== null) {
                $parts[trim($k)] = trim($v);
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || $signature === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->json()->all();

        return new WebhookEvent(
            id: (string) ($payload['id'] ?? ''),
            type: (string) ($payload['type'] ?? ''),
            data: $payload,
        );
    }

    public function startSubscription(
        Organization $organization,
        Plan $plan,
        string $interval,
        array $credentials,
    ): CheckoutResult {
        // TODO: crear Checkout Session vía API de Stripe con el price mapeado.
        throw new GatewayNotImplementedException('La integración de checkout de Stripe está pendiente de configuración.');
    }
}
