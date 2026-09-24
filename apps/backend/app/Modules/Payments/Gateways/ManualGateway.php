<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\CheckoutRequest;
use App\Modules\Payments\Contracts\CheckoutResult;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Contracts\WebhookEvent;
use Illuminate\Http\Request;

/**
 * Pago fuera de línea (transferencia, depósito, efectivo). El cliente recibe las
 * instrucciones configuradas por SUPERADMIN y la factura queda pendiente; el
 * plan se activa sólo cuando SUPERADMIN confirma el pago. No acepta webhooks.
 */
class ManualGateway implements PaymentGatewayInterface
{
    public function key(): string
    {
        return 'manual';
    }

    public function verifyWebhook(Request $request, array $credentials): bool
    {
        return false; // no hay proveedor externo que notifique pagos
    }

    public function verifyCredentials(array $credentials): void
    {
        // Sin API externa que validar.
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        return new WebhookEvent('', '');
    }

    public function startCheckout(CheckoutRequest $checkout, array $credentials): CheckoutResult
    {
        $instructions = trim($credentials['instructions'] ?? '');

        return CheckoutResult::pending(
            $instructions !== ''
                ? $instructions
                : 'Tu solicitud quedó registrada. Te contactaremos con los datos de pago; el plan se activa al confirmar el pago.',
        );
    }

    public function interpretWebhook(WebhookEvent $event, array $credentials): array
    {
        return [];
    }

    public function cancelSubscription(string $gatewaySubscriptionId, array $credentials, bool $atPeriodEnd = false): void
    {
        // Sin cobro recurrente en la pasarela.
    }

    public function resumeSubscription(string $gatewaySubscriptionId, array $credentials): void
    {
        // Sin cobro recurrente en la pasarela.
    }
}
