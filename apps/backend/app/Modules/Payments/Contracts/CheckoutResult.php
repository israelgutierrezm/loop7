<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * Resultado de iniciar una suscripción en una pasarela.
 *
 * - activated=true: la suscripción quedó activa de inmediato (p.ej. pasarela manual).
 * - redirectUrl: URL de checkout a la que redirigir al usuario (pasarelas externas).
 */
final class CheckoutResult
{
    public function __construct(
        public readonly bool $activated,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $gatewaySubscriptionId = null,
    ) {
    }
}
