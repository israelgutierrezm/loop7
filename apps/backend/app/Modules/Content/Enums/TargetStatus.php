<?php

declare(strict_types=1);

namespace App\Modules\Content\Enums;

/**
 * Estado independiente de cada destino de publicación (docs/12: cada target
 * mantiene su propio estado e identificador remoto).
 */
enum TargetStatus: string
{
    case PENDING = 'pending';
    case SCHEDULED = 'scheduled';
    case PUBLISHING = 'publishing';
    case PUBLISHED = 'published';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::SCHEDULED => 'Programado',
            self::PUBLISHING => 'Publicando',
            self::PUBLISHED => 'Publicado',
            self::FAILED => 'Fallido',
            self::CANCELLED => 'Cancelado',
        };
    }
}
