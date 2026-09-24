<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * Resultado de iniciar un cobro:
 * - REDIRECT: el cliente paga en la página de la pasarela (`redirectUrl`); la
 *   suscripción se activa cuando llega el webhook de pago confirmado.
 * - PENDING: pago fuera de línea (manual); SUPERADMIN confirma la factura.
 */
final class CheckoutResult
{
    public const REDIRECT = 'redirect';
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $status,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function redirect(string $url, ?string $gatewayReference = null): self
    {
        return new self(self::REDIRECT, $url, $gatewayReference);
    }

    public static function pending(?string $message = null): self
    {
        return new self(self::PENDING, message: $message);
    }
}
