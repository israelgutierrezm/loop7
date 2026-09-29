<?php

declare(strict_types=1);

namespace App\Modules\Analytics\BestTimes;

use InvalidArgumentException;

/**
 * Una publicación ya medida: su cuenta, cuándo salió (día y hora locales de la
 * marca) y cuántas interacciones logró.
 */
final class PostSample
{
    /**
     * @param string $account cuenta o destino en el que se publicó (base de la comparación)
     * @param int $weekday 1 = lunes … 7 = domingo (ISO-8601), en la zona de la marca
     * @param int $hour 0–23, en la zona de la marca
     */
    public function __construct(
        public readonly string $account,
        public readonly int $weekday,
        public readonly int $hour,
        public readonly int $engagement,
    ) {
        if ($weekday < 1 || $weekday > 7 || $hour < 0 || $hour > 23 || $engagement < 0) {
            throw new InvalidArgumentException('Muestra fuera de rango.');
        }
    }
}
