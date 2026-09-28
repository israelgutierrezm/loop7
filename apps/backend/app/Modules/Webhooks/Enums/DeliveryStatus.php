<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

enum DeliveryStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::SUCCEEDED => 'Entregado',
            self::FAILED => 'Fallido',
        };
    }
}
