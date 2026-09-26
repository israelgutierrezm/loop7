<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Enums;

enum MembershipStatus: string
{
    // Las invitaciones pendientes viven en organization_invitations, no aquí.
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo',
            self::SUSPENDED => 'Suspendido',
        };
    }
}
