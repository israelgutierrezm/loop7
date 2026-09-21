<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Billing\Models\Plan;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Contracts\CheckoutResult;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Contracts\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pasarela manual (sin proveedor externo). Útil para desarrollo, planes cortesía
 * y cobros gestionados fuera de línea. Activa la suscripción de inmediato.
 */
class ManualGateway implements PaymentGatewayInterface
{
    public function key(): string
    {
        return 'manual';
    }

    public function verifyWebhook(Request $request, array $credentials): bool
    {
        $secret = $credentials['webhook_secret'] ?? null;

        if ($secret === null || $secret === '') {
            return true;
        }

        return hash_equals($secret, (string) $request->header('X-Webhook-Secret'));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->json()->all();

        return new WebhookEvent(
            id: (string) ($payload['id'] ?? Str::uuid()->toString()),
            type: (string) ($payload['type'] ?? 'manual.event'),
            data: $payload,
        );
    }

    public function startSubscription(
        Organization $organization,
        Plan $plan,
        string $interval,
        array $credentials,
    ): CheckoutResult {
        return new CheckoutResult(
            activated: true,
            gatewaySubscriptionId: 'manual_' . Str::ulid()->toString(),
        );
    }
}
