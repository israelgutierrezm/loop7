<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Enums\ReportFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Próximo envío automático de un informe: el día y la hora elegidos en la zona
 * horaria de la marca (respeta el cambio de horario), guardado en UTC.
 */
final class ReportSchedule
{
    public static function next(?ReportFrequency $frequency, ?int $day, int $hour, string $timezone, ?CarbonImmutable $after = null): ?CarbonImmutable
    {
        if ($frequency === null || $day === null) {
            return null;
        }

        $local = ($after ?? CarbonImmutable::now())->setTimezone($timezone);

        if ($frequency === ReportFrequency::WEEKLY) {
            $candidate = $local->startOfWeek(CarbonInterface::MONDAY)->addDays($day - 1)->setTime($hour, 0);
            if ($candidate->lessThanOrEqualTo($local)) {
                $candidate = $candidate->addWeek()->setTime($hour, 0);
            }
        } else {
            $candidate = $local->startOfMonth()->setDay($day)->setTime($hour, 0);
            if ($candidate->lessThanOrEqualTo($local)) {
                $candidate = $local->startOfMonth()->addMonthNoOverflow()->setDay($day)->setTime($hour, 0);
            }
        }

        return $candidate->utc();
    }
}
