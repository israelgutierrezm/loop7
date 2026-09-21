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
 * Adaptador de Mercado Pago (esqueleto MVP). Verificación de firma best-effort
 * por HMAC sobre el cuerpo; el manifest específico y el checkout quedan como TODO.
 */
class MercadoPagoGateway implements PaymentGatewayInterface
{
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

        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, null);
            if ($k !== null && $v !== null) {
                $parts[trim($k)] = trim($v);
            }
        }

        $signature = $parts['v1'] ?? null;
        if ($signature === null) {
            return false;
        }

        // TODO: usar el manifest oficial (id;request-id;ts). Best-effort sobre el cuerpo.
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->json()->all();

        return new WebhookEvent(
            id: (string) ($payload['id'] ?? $request->query('id', '')),
            type: (string) ($payload['type'] ?? $payload['action'] ?? ''),
            data: $payload,
        );
    }

    public function startSubscription(
        Organization $organization,
        Plan $plan,
        string $interval,
        array $credentials,
    ): CheckoutResult {
        throw new GatewayNotImplementedException('La integración de checkout de Mercado Pago está pendiente de configuración.');
    }
}
