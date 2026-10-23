<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Periodo que cubre un informe, siempre con días completos en la zona horaria de
 * la marca (hoy no cuenta: sus métricas aún no están cerradas).
 */
enum ReportPeriod: string
{
    case LAST_7_DAYS = 'last_7_days';
    case LAST_30_DAYS = 'last_30_days';
    case LAST_90_DAYS = 'last_90_days';
    case PREVIOUS_WEEK = 'previous_week';
    case PREVIOUS_MONTH = 'previous_month';

    public function label(): string
    {
        return match ($this) {
            self::LAST_7_DAYS => 'Últimos 7 días',
            self::LAST_30_DAYS => 'Últimos 30 días',
            self::LAST_90_DAYS => 'Últimos 90 días',
            self::PREVIOUS_WEEK => 'Semana anterior (lunes a domingo)',
            self::PREVIOUS_MONTH => 'Mes anterior',
        };
    }

    /**
     * Primer y último día (fechas) del periodo visto desde `$now` en `$timezone`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(string $timezone, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $yesterday = $today->subDay();

        return match ($this) {
            self::LAST_7_DAYS => [$yesterday->subDays(6), $yesterday],
            self::LAST_30_DAYS => [$yesterday->subDays(29), $yesterday],
            self::LAST_90_DAYS => [$yesterday->subDays(89), $yesterday],
            self::PREVIOUS_WEEK => [$today->startOfWeek(CarbonInterface::MONDAY)->subWeek(), $today->startOfWeek(CarbonInterface::MONDAY)->subDay()],
            self::PREVIOUS_MONTH => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }
}
