<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Enums;

enum KnowledgeDocumentStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En cola',
            self::PROCESSING => 'Procesando',
            self::READY => 'Listo',
            self::FAILED => 'Error',
        };
    }
}
