<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Enums;

enum InvitationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REVOKED = 'revoked';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::ACCEPTED => 'Aceptada',
            self::REVOKED => 'Revocada',
            self::EXPIRED => 'Expirada',
        };
    }
}
