<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Estados de suscripción (docs/08_BILLING_PAGOS.md).
 */
enum SubscriptionStatus: string
{
    case TRIALING = 'trialing';
    case ACTIVE = 'active';
    case PAST_DUE = 'past_due';
    case GRACE = 'grace';
    case SUSPENDED = 'suspended';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::TRIALING => 'En prueba',
            self::ACTIVE => 'Activa',
            self::PAST_DUE => 'Pago pendiente',
            self::GRACE => 'Periodo de gracia',
            self::SUSPENDED => 'Suspendida',
            self::CANCELLED => 'Cancelada',
            self::EXPIRED => 'Expirada',
        };
    }

    /**
     * ¿La suscripción da acceso operativo al producto?
     */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::TRIALING, self::ACTIVE, self::GRACE], true);
    }
}
