<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Estado de una ejecución. «En espera» la retoma el planificador cuando vence
 * su paso «Esperar»; «Cancelada», si al retomarla la regla ya no puede seguir.
 */
enum AutomationRunStatus: string
{
    case RUNNING = 'running';
    case WAITING = 'waiting';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::RUNNING => 'En curso',
            self::WAITING => 'En espera',
            self::SUCCESS => 'Correcta',
            self::FAILED => 'Fallida',
            self::SKIPPED => 'Omitida',
            self::CANCELLED => 'Cancelada',
        };
    }
}
