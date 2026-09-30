<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\BestTimes\BestTimesCalculator;
use App\Modules\Analytics\BestTimes\PostSample;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\JoinClause;

/**
 * Mejores horarios de una marca (docs/05). Usa el último snapshot de cada
 * publicación de los últimos 90 días con al menos 48 h de vida (cuando sus
 * métricas ya se asentaron), las agrupa por día y hora en la zona de la marca y
 * devuelve el mapa de calor, las franjas recomendadas y sus próximas fechas.
 */
class BestTimesService
{
    public const WINDOW_DAYS = 90;

    public const MATURITY_HOURS = 48;

    /** Antelación mínima de una fecha sugerida (la misma que al arrastrar en el calendario). */
    private const LEAD_MINUTES = 10;

    public function __construct(
        private readonly BestTimesCalculator $calculator,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    /**
     * Los mejores horarios son parte de la analítica avanzada del plan (docs/08).
     *
     * @throws PlanLimitExceededException
     */
    public function ensureAvailable(?Organization $organization): void
    {
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_ANALYTICS_ADVANCED)) {
            throw new PlanLimitExceededException(
                'Los mejores horarios para publicar requieren un plan con analítica avanzada.',
                Entitlement::FEATURE_ANALYTICS_ADVANCED,
            );
        }
    }

    /**
     * @param list<string> $providers redes a considerar (vacío = todas)
     * @return array<string, mixed>
     */
    public function forBrand(Brand $brand, array $providers, CarbonInterface $from, CarbonInterface $to): array
    {
        $timezone = $brand->timezone ?: 'UTC';
        $result = $this->calculator->calculate($this->samples($brand, $providers, $timezone));

        $heatmap = [];
        $counts = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $slot = BestTimesCalculator::slot($weekday, $hour);
                $score = $result->scores[$slot];
                $heatmap[$weekday - 1][$hour] = $score === null ? null : round($score, 3);
                $counts[$weekday - 1][$hour] = $result->counts[$slot];
            }
        }

        return [
            'timezone' => $timezone,
            'providers' => $providers,
            'window_days' => self::WINDOW_DAYS,
            'sample' => $result->sample,
            'min_posts' => BestTimesCalculator::MIN_POSTS,
            'sufficient' => $result->sufficient,
            'heatmap' => $heatmap,
            'counts' => $counts,
            'top' => $result->top,
            'occurrences' => $this->occurrences($result->top, $timezone, $from, $to),
        ];
    }

    /**
     * @param list<string> $providers
     * @return list<PostSample>
     */
    private function samples(Brand $brand, array $providers, string $timezone): array
    {
        $now = CarbonImmutable::now();

        // Último snapshot de cada publicación (los snapshots guardan totales acumulados).
        $latest = PostMetricSnapshot::query()
            ->where('brand_id', $brand->id)
            ->selectRaw('publication_target_id, MAX(date) AS last_date')
            ->groupBy('publication_target_id');

        $rows = PostMetricSnapshot::query()
            ->joinSub($latest, 'latest', function (JoinClause $join): void {
                $join->on('latest.publication_target_id', '=', 'post_metric_snapshots.publication_target_id')
                    ->on('latest.last_date', '=', 'post_metric_snapshots.date');
            })
            ->join('publication_targets as t', 't.id', '=', 'post_metric_snapshots.publication_target_id')
            ->whereColumn('t.organization_id', 'post_metric_snapshots.organization_id')
            ->where('post_metric_snapshots.brand_id', $brand->id)
            ->whereBetween('t.published_at', [
                $now->subDays(self::WINDOW_DAYS),
                $now->subHours(self::MATURITY_HOURS),
            ])
            ->when($providers !== [], fn ($q) => $q->whereIn('post_metric_snapshots.provider', $providers))
            ->toBase()
            ->get([
                'post_metric_snapshots.provider',
                'post_metric_snapshots.engagement',
                't.published_at',
                't.social_connection_destination_id',
            ]);

        $samples = [];
        foreach ($rows as $row) {
            $local = CarbonImmutable::parse((string) $row->published_at, 'UTC')->setTimezone($timezone);
            $samples[] = new PostSample(
                account: $row->social_connection_destination_id !== null
                    ? 'd' . $row->social_connection_destination_id
                    : 'p' . $row->provider,
                weekday: $local->dayOfWeekIso,
                hour: $local->hour,
                engagement: max(0, (int) $row->engagement),
            );
        }

        return $samples;
    }

    /**
     * Fechas de las franjas recomendadas dentro de [from, to], contadas en la zona
     * de la marca (respeta el cambio de horario) y sólo a partir de ahora.
     *
     * @param list<array{weekday: int, hour: int, lift: int, posts: int}> $top
     * @return list<array{at: string, weekday: int, hour: int, lift: int}>
     */
    private function occurrences(array $top, string $timezone, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($top === []) {
            return [];
        }

        $earliest = CarbonImmutable::now()->addMinutes(self::LEAD_MINUTES);
        $start = CarbonImmutable::instance($from);
        $end = CarbonImmutable::instance($to);

        $occurrences = [];
        for ($day = $start->setTimezone($timezone)->startOfDay(); $day <= $end; $day = $day->addDay()) {
            foreach ($top as $slot) {
                if ($slot['weekday'] !== $day->dayOfWeekIso) {
                    continue;
                }
                $at = $day->setTime($slot['hour'], 0);
                // Hora inexistente ese día (adelanto de horario) o fuera del rango pedido.
                if ($at->hour !== $slot['hour'] || $at < $earliest || $at < $start || $at > $end) {
                    continue;
                }
                $occurrences[] = [
                    'at' => $at->utc()->toIso8601String(),
                    'weekday' => $slot['weekday'],
                    'hour' => $slot['hour'],
                    'lift' => $slot['lift'],
                ];
            }
        }

        usort($occurrences, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $occurrences;
    }
}
