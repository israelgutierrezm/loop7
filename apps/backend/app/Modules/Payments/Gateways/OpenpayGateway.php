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
use RuntimeException;

/**
 * Adaptador de Openpay (esqueleto MVP). Openpay usa verificación por secreto
 * compartido; el checkout queda como TODO.
 */
class OpenpayGateway implements PaymentGatewayInterface
{
    public function key(): string
    {
        return 'openpay';
    }

    public function verifyWebhook(Request $request, array $credentials): bool
    {
        $secret = $credentials['webhook_secret'] ?? '';

        if ($secret === '') {
            return false;
        }

        // Openpay entrega un "verification_code" en el alta del webhook.
        $provided = (string) ($request->json('verification_code')
            ?? $request->header('X-Webhook-Secret', ''));

        return $provided !== '' && hash_equals($secret, $provided);
    }

    public function verifyCredentials(array $credentials): void
    {
        // Openpay requiere merchant_id y URL de entorno específicos, aún no modelados
        // en el formulario genérico de credenciales.
        throw new RuntimeException('La prueba de conexión de Openpay aún no está disponible.');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->json()->all();

        return new WebhookEvent(
            id: (string) ($payload['id'] ?? ($payload['transaction']['id'] ?? '')),
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
        throw new GatewayNotImplementedException('La integración de checkout de Openpay está pendiente de configuración.');
    }
}
