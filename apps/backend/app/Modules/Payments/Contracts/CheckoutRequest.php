<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * Datos para iniciar el cobro de un plan en una pasarela, independientes del
 * proveedor. `reference` es el id público de la factura pendiente: la pasarela
 * lo devuelve en sus webhooks para enlazar el pago con la organización.
 */
final class CheckoutRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly string $organizationId,
        public readonly string $planKey,
        public readonly string $planName,
        public readonly string $interval,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $customerEmail,
        public readonly string $customerName,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
    ) {
    }

    public function description(): string
    {
        return 'Loop7 · Plan ' . $this->planName . ' (' . ($this->interval === 'year' ? 'anual' : 'mensual') . ')';
    }

    /**
     * Importe decimal (p. ej. 49.00) para pasarelas que no usan centavos.
     */
    public function amount(): float
    {
        return round($this->amountCents / 100, 2);
    }
}
