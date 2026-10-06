<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Cómo se combinan las condiciones de un paso «Condición».
 */
enum ConditionMatch: string
{
    case ALL = 'all';
    case ANY = 'any';

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'Se cumplen todas',
            self::ANY => 'Se cumple alguna',
        };
    }
}
