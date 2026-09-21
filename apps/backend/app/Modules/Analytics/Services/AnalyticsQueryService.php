<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\AccountMetricSnapshot;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\PublicationTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consultas de analítica sobre snapshots (docs/05): KPIs, series temporales,
 * comparación de periodos, desglose por canal y top de publicaciones. Todo se
 * acota por Brand dentro de la Organization (el OrganizationScope aísla el tenant).
 */
class AnalyticsQueryService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(Brand $brand, Carbon $from, Carbon $to): array
    {
        $totals = $this->rangeTotals($brand, $from, $to);
        $followers = $this->currentFollowers($brand, $from, $to);

        // Periodo anterior de igual longitud para comparar.
        $lengthDays = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1);
        $prevTotals = $this->rangeTotals($brand, $prevFrom, $prevTo);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'followers' => $followers,
                'reach' => (int) $totals->reach,
                'impressions' => (int) $totals->impressions,
                'engagement' => (int) $totals->engagement,
                'posts_published' => $this->postsPublished($brand, $from, $to),
            ],
            'deltas' => [
                'reach' => $this->delta((int) $totals->reach, (int) $prevTotals->reach),
                'impressions' => $this->delta((int) $totals->impressions, (int) $prevTotals->impressions),
                'engagement' => $this->delta((int) $totals->engagement, (int) $prevTotals->engagement),
            ],
            'series' => $this->series($brand, $from, $to),
            'by_channel' => $this->byChannel($brand, $from, $to),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topPosts(Brand $brand, Carbon $from, Carbon $to, int $limit = 10): array
    {
        $latest = PostMetricSnapshot::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('publication_target_id')
            ->map(fn (Collection $g) => $g->sortByDesc('date')->first())
            ->sortByDesc(fn (PostMetricSnapshot $s) => $s->engagement)
            ->take($limit)
            ->values();

        if ($latest->isEmpty()) {
            return [];
        }

        $targets = PublicationTarget::query()->withoutGlobalScopes()
            ->with(['variant.contentItem', 'destination'])
            ->whereIn('id', $latest->pluck('publication_target_id'))
            ->get()
            ->keyBy('id');

        return $latest->map(function (PostMetricSnapshot $s) use ($targets): array {
            $target = $targets->get($s->publication_target_id);
            $content = $target?->variant?->contentItem;

            return [
                'id' => $target?->public_id,
                'title' => $content->title,
                'provider' => $s->provider,
                'destination' => $target?->destination?->name,
                'remote_url' => $target?->remote_url,
                'impressions' => $s->impressions,
                'reach' => $s->reach,
                'likes' => $s->likes,
                'comments' => $s->comments,
                'shares' => $s->shares,
                'engagement' => $s->engagement,
            ];
        })->all();
    }

    private function rangeTotals(Brand $brand, Carbon $from, Carbon $to): object
    {
        return AccountMetricSnapshot::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(reach),0) reach, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(engagement),0) engagement')
            ->first() ?? (object) ['reach' => 0, 'impressions' => 0, 'engagement' => 0];
    }

    /**
     * Seguidores actuales = último snapshot dentro del rango por destino, sumado.
     */
    private function currentFollowers(Brand $brand, Carbon $from, Carbon $to): int
    {
        return (int) AccountMetricSnapshot::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('social_connection_destination_id')
            ->sum(fn (Collection $g) => $g->sortByDesc('date')->first()->followers);
    }

    private function postsPublished(Brand $brand, Carbon $from, Carbon $to): int
    {
        return (int) PostMetricSnapshot::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->distinct('publication_target_id')
            ->count('publication_target_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function series(Brand $brand, Carbon $from, Carbon $to): array
    {
        return AccountMetricSnapshot::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date, SUM(impressions) impressions, SUM(reach) reach, SUM(engagement) engagement')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->getRawOriginal('date'))->toDateString(),
                'impressions' => (int) $r->impressions,
                'reach' => (int) $r->reach,
                'engagement' => (int) $r->engagement,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byChannel(Brand $brand, Carbon $from, Carbon $to): array
    {
        return AccountMetricSnapshot::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('provider, SUM(reach) reach, SUM(impressions) impressions, SUM(engagement) engagement')
            ->groupBy('provider')
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'reach' => (int) $r->reach,
                'impressions' => (int) $r->impressions,
                'engagement' => (int) $r->engagement,
            ])
            ->all();
    }

    /**
     * @return array{value: int, pct: float|null}
     */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['value' => $current, 'pct' => $current > 0 ? 100.0 : null];
        }

        return ['value' => $current - $previous, 'pct' => round((($current - $previous) / $previous) * 100, 1)];
    }
}
