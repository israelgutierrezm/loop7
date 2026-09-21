<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Enums;

enum OrganizationStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activa',
            self::SUSPENDED => 'Suspendida',
            self::CANCELLED => 'Cancelada',
        };
    }
}
