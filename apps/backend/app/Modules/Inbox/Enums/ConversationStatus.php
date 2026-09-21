<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Enums;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case PENDING = 'pending';
    case RESOLVED = 'resolved';
    case SNOOZED = 'snoozed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Abierta',
            self::PENDING => 'Pendiente',
            self::RESOLVED => 'Resuelta',
            self::SNOOZED => 'Pospuesta',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
