<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Enums;

enum MembershipStatus: string
{
    case ACTIVE = 'active';
    case INVITED = 'invited';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo',
            self::INVITED => 'Invitado',
            self::SUSPENDED => 'Suspendido',
        };
    }
}
