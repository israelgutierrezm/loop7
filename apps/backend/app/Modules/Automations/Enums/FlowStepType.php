<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Pasos del flujo de una automatización (docs/05): una acción, una espera o una
 * condición con dos caminos («Sí» y «No»).
 */
enum FlowStepType: string
{
    case ACTION = 'action';
    case WAIT = 'wait';
    case BRANCH = 'branch';

    public function label(): string
    {
        return match ($this) {
            self::ACTION => 'Acción',
            self::WAIT => 'Esperar',
            self::BRANCH => 'Condición (Sí / No)',
        };
    }
}
