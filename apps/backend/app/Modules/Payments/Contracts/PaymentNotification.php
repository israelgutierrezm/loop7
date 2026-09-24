<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use Illuminate\Support\Carbon;

/**
 * Hecho de pago normalizado a partir de un webhook de cualquier pasarela. El
 * dominio de billing sólo trabaja con estos hechos, nunca con payloads crudos.
 */
final class PaymentNotification
{
    /** Pago inicial confirmado de una factura pendiente (`reference`): activa el plan. */
    public const CHECKOUT_COMPLETED = 'checkout_completed';

    /** Cobro recurrente confirmado de una suscripción de la pasarela: renueva el periodo. */
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';

    /** Cobro recurrente rechazado: la suscripción entra en periodo de gracia. */
    public const PAYMENT_FAILED = 'payment_failed';

    /** La pasarela canceló la suscripción. */
    public const SUBSCRIPTION_CANCELLED = 'subscription_cancelled';

    public function __construct(
        public readonly string $type,
        public readonly ?string $reference = null,
        public readonly ?string $gatewaySubscriptionId = null,
        public readonly ?string $transactionId = null,
        public readonly ?int $amountCents = null,
        public readonly ?string $currency = null,
        public readonly ?Carbon $periodEnd = null,
    ) {
    }
}
