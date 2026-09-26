<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Enums;

enum OrganizationStatus: string
{
    // La eliminación es lógica (SoftDeletes); la suscripción tiene su propio estado.
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activa',
            self::SUSPENDED => 'Suspendida',
        };
    }
}
