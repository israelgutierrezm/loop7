<?php

declare(strict_types=1);

namespace App\Modules\Analytics\BestTimes;

/**
 * Mejores horarios para publicar según el rendimiento histórico (docs/05).
 *
 * Cada publicación se compara con lo habitual de su cuenta (la mediana de sus
 * interacciones): así una cuenta grande no tapa a una pequeña y el crecimiento no
 * sesga. Cada franja (día de la semana × hora) promedia esas puntuaciones con un
 * suavizado a las horas vecinas y una contracción hacia «lo habitual» (1,0), de
 * modo que una sola publicación afortunada no decide una franja.
 */
final class BestTimesCalculator
{
    /** Publicaciones con señal necesarias para recomendar. */
    public const MIN_POSTS = 10;

    /** Franjas recomendadas como máximo. */
    public const TOP_LIMIT = 5;

    /** Peso de «lo habitual» (1,0) en cada franja: contracción hacia la media. */
    private const PRIOR_WEIGHT = 2.0;

    /** Aporte de una publicación a las horas vecinas (±1 h). */
    private const NEIGHBOR_WEIGHT = 0.5;

    /**
     * Tope de la puntuación de una publicación: más allá de 3 veces lo habitual
     * pesa el contenido, no la hora, y un viral no decide solo una franja.
     */
    private const SCORE_CAP = 3.0;

    /** Mejora mínima sobre lo habitual para recomendar una franja (5 %). */
    private const MIN_LIFT = 0.05;

    private const SLOTS = 168;

    /**
     * @param list<PostSample> $samples
     */
    public function calculate(array $samples): BestTimesResult
    {
        $scored = $this->scoreAgainstAccount($samples);

        $weights = array_fill(0, self::SLOTS, 0.0);
        $sums = array_fill(0, self::SLOTS, 0.0);
        $counts = array_fill(0, self::SLOTS, 0);
        foreach ($scored as [$slot, $score]) {
            $counts[$slot]++;
            $spread = [
                $slot => 1.0,
                ($slot + self::SLOTS - 1) % self::SLOTS => self::NEIGHBOR_WEIGHT,
                ($slot + 1) % self::SLOTS => self::NEIGHBOR_WEIGHT,
            ];
            foreach ($spread as $target => $weight) {
                $weights[$target] += $weight;
                $sums[$target] += $weight * $score;
            }
        }

        $scores = [];
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $scores[$slot] = $weights[$slot] > 0
                ? ($sums[$slot] + self::PRIOR_WEIGHT) / ($weights[$slot] + self::PRIOR_WEIGHT)
                : null;
        }

        $sufficient = count($scored) >= self::MIN_POSTS;

        return new BestTimesResult(
            sample: count($scored),
            sufficient: $sufficient,
            scores: $scores,
            counts: $counts,
            top: $sufficient ? $this->top($scores, $counts) : [],
        );
    }

    public static function slot(int $weekday, int $hour): int
    {
        return ($weekday - 1) * 24 + $hour;
    }

    /**
     * Puntuación de cada publicación frente a lo habitual de su cuenta. Las
     * cuentas sin interacciones no aportan señal y se descartan.
     *
     * @param list<PostSample> $samples
     * @return list<array{0: int, 1: float}> [franja, puntuación]
     */
    private function scoreAgainstAccount(array $samples): array
    {
        $byAccount = [];
        foreach ($samples as $sample) {
            $byAccount[$sample->account][] = $sample;
        }

        $scored = [];
        foreach ($byAccount as $accountSamples) {
            $baseline = $this->baseline(array_map(fn (PostSample $s): int => $s->engagement, $accountSamples));
            if ($baseline <= 0.0) {
                continue;
            }
            foreach ($accountSamples as $sample) {
                $scored[] = [
                    self::slot($sample->weekday, $sample->hour),
                    min($sample->engagement / $baseline, self::SCORE_CAP),
                ];
            }
        }

        return $scored;
    }

    /**
     * Lo habitual de una cuenta: su mediana (robusta ante virales) o, si la
     * mayoría no tuvo interacciones, su media.
     *
     * @param list<int> $values
     */
    private function baseline(array $values): float
    {
        sort($values);
        $n = count($values);
        $median = $n % 2 === 1
            ? (float) $values[intdiv($n, 2)]
            : ($values[intdiv($n, 2) - 1] + $values[intdiv($n, 2)]) / 2;

        return $median > 0 ? $median : array_sum($values) / $n;
    }

    /**
     * Mejores franjas con al menos una publicación propia y una mejora mínima,
     * separadas entre sí (dos horas seguidas comparten datos por el suavizado).
     *
     * @param array<int, float|null> $scores
     * @param array<int, int> $counts
     * @return list<array{weekday: int, hour: int, lift: int, posts: int}>
     */
    private function top(array $scores, array $counts): array
    {
        $candidates = [];
        foreach ($scores as $slot => $score) {
            if ($score !== null && $counts[$slot] > 0 && $score - 1.0 >= self::MIN_LIFT) {
                $candidates[] = $slot;
            }
        }
        // Mejor puntuación primero; a igualdad, más publicaciones y luego antes en la semana.
        usort($candidates, fn (int $a, int $b): int => [$scores[$b], $counts[$b], $a] <=> [$scores[$a], $counts[$a], $b]);

        $picked = [];
        foreach ($candidates as $slot) {
            foreach ($picked as $other) {
                $distance = abs($slot - $other);
                if (min($distance, self::SLOTS - $distance) < 2) {
                    continue 2;
                }
            }
            $picked[] = $slot;
            if (count($picked) === self::TOP_LIMIT) {
                break;
            }
        }

        return array_map(fn (int $slot): array => [
            'weekday' => intdiv($slot, 24) + 1,
            'hour' => $slot % 24,
            'lift' => (int) round(((float) $scores[$slot] - 1.0) * 100),
            'posts' => $counts[$slot],
        ], $picked);
    }
}
