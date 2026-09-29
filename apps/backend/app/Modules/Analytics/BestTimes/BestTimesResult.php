<?php

declare(strict_types=1);

namespace App\Modules\Analytics\BestTimes;

/**
 * Resultado del cálculo. Las franjas son las 168 horas de la semana en la zona de
 * la marca: índice = (día ISO − 1) × 24 + hora.
 */
final class BestTimesResult
{
    /**
     * @param int $sample publicaciones que aportan señal (con interacciones en su cuenta)
     * @param array<int, float|null> $scores rendimiento por franja (1,0 = lo habitual; null = sin datos)
     * @param array<int, int> $counts publicaciones que salieron en cada franja
     * @param list<array{weekday: int, hour: int, lift: int, posts: int}> $top franjas recomendadas, de mejor a peor
     */
    public function __construct(
        public readonly int $sample,
        public readonly bool $sufficient,
        public readonly array $scores,
        public readonly array $counts,
        public readonly array $top,
    ) {
    }
}
