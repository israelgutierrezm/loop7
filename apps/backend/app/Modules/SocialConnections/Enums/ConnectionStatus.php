<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Enums;

enum ConnectionStatus: string
{
    case CONNECTED = 'connected';
    case EXPIRED = 'expired';
    case REVOKED = 'revoked';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::CONNECTED => 'Conectada',
            self::EXPIRED => 'Expirada',
            self::REVOKED => 'Revocada',
            self::ERROR => 'Con error',
        };
    }

    public function needsAttention(): bool
    {
        return $this !== self::CONNECTED;
    }
}
