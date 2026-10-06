<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Unidades del paso «Esperar».
 */
enum WaitUnit: string
{
    case MINUTES = 'minutes';
    case HOURS = 'hours';
    case DAYS = 'days';

    public function label(): string
    {
        return match ($this) {
            self::MINUTES => 'minutos',
            self::HOURS => 'horas',
            self::DAYS => 'días',
        };
    }

    public function seconds(int $amount): int
    {
        return $amount * match ($this) {
            self::MINUTES => 60,
            self::HOURS => 3600,
            self::DAYS => 86400,
        };
    }

    /**
     * «2 horas», «1 día»…
     */
    public function describe(int $amount): string
    {
        if ($amount === 1) {
            return match ($this) {
                self::MINUTES => '1 minuto',
                self::HOURS => '1 hora',
                self::DAYS => '1 día',
            };
        }

        return "{$amount} {$this->label()}";
    }
}
