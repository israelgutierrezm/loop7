<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

enum BillingInterval: string
{
    case MONTH = 'month';
    case YEAR = 'year';

    public function label(): string
    {
        return match ($this) {
            self::MONTH => 'Mensual',
            self::YEAR => 'Anual',
        };
    }
}
