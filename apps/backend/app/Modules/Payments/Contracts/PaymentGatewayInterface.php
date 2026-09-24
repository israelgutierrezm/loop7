<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use Illuminate\Http\Request;

/**
 * Contrato de pasarela de pago. El dominio nunca se acopla a Stripe/Mercado
 * Pago/Openpay: interactúa a través de este contrato (docs/08). Las
 * credenciales llegan ya resueltas para el entorno activo (test/producción) e
 * incluyen `environment` y los ajustes no secretos de la pasarela.
 */
interface PaymentGatewayInterface
{
    /**
     * Identificador estable de la pasarela (stripe, mercadopago, openpay, manual).
     */
    public function key(): string;

    /**
     * Verifica la autenticidad del webhook (firma HMAC, Basic auth…).
     *
     * @param  array<string, string>  $credentials
     */
    public function verifyWebhook(Request $request, array $credentials): bool;

    /**
     * Verifica que las credenciales del entorno son válidas ("probar conexión").
     * Lanza una excepción con un mensaje legible si la verificación falla.
     *
     * @param  array<string, string>  $credentials
     */
    public function verifyCredentials(array $credentials): void;

    /**
     * Normaliza el webhook a un WebhookEvent (id único + tipo + datos).
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * Inicia el cobro de un plan (checkout alojado por la pasarela o pago manual).
     *
     * @param  array<string, string>  $credentials
     */
    public function startCheckout(CheckoutRequest $checkout, array $credentials): CheckoutResult;

    /**
     * Traduce un evento de webhook a hechos de pago. Si la pasarela sólo notifica
     * ids, consulta su API para obtener el estado real (nunca se confía en el
     * cuerpo sin verificar). Devuelve [] si el evento no afecta al billing.
     *
     * @param  array<string, string>  $credentials
     * @return list<PaymentNotification>
     */
    public function interpretWebhook(WebhookEvent $event, array $credentials): array;

    /**
     * Cancela en la pasarela una suscripción recurrente: de inmediato (al cambiar
     * de plan o de pasarela) o al final del periodo (cancelación del cliente).
     * No-op en pasarelas sin cobro recurrente.
     *
     * @param  array<string, string>  $credentials
     */
    public function cancelSubscription(string $gatewaySubscriptionId, array $credentials, bool $atPeriodEnd = false): void;

    /**
     * Revierte una cancelación programada al final del periodo.
     *
     * @param  array<string, string>  $credentials
     */
    public function resumeSubscription(string $gatewaySubscriptionId, array $credentials): void;
}
