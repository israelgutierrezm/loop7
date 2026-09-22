<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Billing\Models\Plan;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Http\Request;

/**
 * Contrato de pasarela de pago. El dominio nunca se acopla a Stripe/MercadoPago/
 * Openpay: interactúa a través de este contrato (docs/08).
 */
interface PaymentGatewayInterface
{
    /**
     * Identificador estable de la pasarela (stripe, mercadopago, openpay, manual).
     */
    public function key(): string;

    /**
     * Verifica la firma del webhook con las credenciales del entorno.
     *
     * @param  array<string, string>  $credentials
     */
    public function verifyWebhook(Request $request, array $credentials): bool;

    /**
     * Verifica que las credenciales del entorno son válidas ("probar conexión").
     * Lanza una excepción si la verificación falla.
     *
     * @param  array<string, string>  $credentials
     */
    public function verifyCredentials(array $credentials): void;

    /**
     * Normaliza el webhook a un WebhookEvent (id + tipo + datos).
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * Inicia una suscripción para la Organization en el plan/intervalo indicados.
     *
     * @param  array<string, string>  $credentials
     */
    public function startSubscription(
        Organization $organization,
        Plan $plan,
        string $interval,
        array $credentials,
    ): CheckoutResult;
}
