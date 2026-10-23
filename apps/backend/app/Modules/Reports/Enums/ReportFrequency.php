<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/**
 * Envío automático de un informe a sus destinatarios.
 */
enum ReportFrequency: string
{
    /** Cada semana, el día ISO `send_day` (1 = lunes … 7 = domingo). */
    case WEEKLY = 'weekly';
    /** Cada mes, el día `send_day` (1–28: existe en todos los meses). */
    case MONTHLY = 'monthly';

    public function maxDay(): int
    {
        return match ($this) {
            self::WEEKLY => 7,
            self::MONTHLY => 28,
        };
    }
}
