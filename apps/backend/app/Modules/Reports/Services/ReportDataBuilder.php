<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Analytics\Models\AccountMetricSnapshot;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Analytics\Services\BestTimesService;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Competitors\Services\CompetitorBenchmark;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\OrganizationBranding;
use App\Modules\Reports\Enums\ReportSection;
use App\Modules\Reports\Models\ClientReport;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Support\Tenancy\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Datos de un informe para clientes (docs/05): una foto de las métricas de la
 * marca en el periodo, sección a sección. Se guarda con el informe generado y es
 * lo que se pinta en el PDF (nunca se recalcula después).
 *
 * Los días son de la zona horaria de la marca; las publicaciones cuentan por su
 * fecha de publicación y sus interacciones son las de su última medición.
 */
final class ReportDataBuilder
{
    public const TOP_POSTS = 10;

    private const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public function __construct(
        private readonly BestTimesService $bestTimes,
        private readonly CompetitorBenchmark $benchmark,
        private readonly EntitlementsService $entitlements,
        private readonly OrganizationBranding $branding,
    ) {
    }

    /**
     * Secciones elegidas que el plan permite hoy (las demás se omiten del PDF).
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    public function availableSections(Organization $organization, array $sections): array
    {
        return array_values(array_filter(
            ReportSection::values(),
            fn (string $section): bool => in_array($section, $sections, true) && $this->sectionAvailable($organization, $section),
        ));
    }

    public function sectionAvailable(Organization $organization, string $section): bool
    {
        return match ($section) {
            ReportSection::BEST_TIMES->value => $this->entitlements->allows($organization, Entitlement::FEATURE_ANALYTICS_ADVANCED),
            ReportSection::COMPETITORS->value => $this->entitlements->limit($organization, Entitlement::COMPETITOR_ACCOUNTS_MAX) !== 0,
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ClientReport $report, Brand $brand, Organization $organization, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = $brand->timezone ?: 'UTC';
        $from = $from->setTimezone($timezone)->startOfDay();
        $to = $to->setTimezone($timezone)->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $sections = $this->availableSections($organization, $report->sections);
        $branding = $this->branding->active($organization);

        $data = [
            'version' => 1,
            'name' => $report->name,
            'brand' => ['name' => $brand->name, 'timezone' => $timezone],
            'organization' => ['name' => $organization->name],
            'branding' => [
                'name' => $branding['name'] ?? (string) config('app.name'),
                'color' => $branding['color'] ?? null,
                'white_label' => $branding !== null,
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'previous_from' => $previousFrom->toDateString(),
                'previous_to' => $previousTo->toDateString(),
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'intro' => $report->intro,
            'sections' => $sections,
        ];

        $providers = SocialProvider::query()->pluck('name', 'key')->all();
        $current = $this->accountSnapshots($organization, $brand, $from, $to);
        $previous = $this->accountSnapshots($organization, $brand, $previousFrom, $previousTo);
        $published = $this->publishedTargets($organization, $brand, $from, $to, $timezone);

        foreach ($sections as $section) {
            $data[$section] = match ($section) {
                ReportSection::SUMMARY->value => $this->summary($current, $previous, $published, $this->publishedTargets($organization, $brand, $previousFrom, $previousTo, $timezone)->count()),
                ReportSection::CHANNELS->value => $this->channels($current, $published, $providers),
                ReportSection::GROWTH->value => $this->growth($current, $from, $to),
                ReportSection::TOP_POSTS->value => $this->topPosts($published, $providers, $timezone),
                ReportSection::PUBLISHING->value => $this->publishing($organization, $brand, $published, $providers, $from, $to, $timezone),
                ReportSection::BEST_TIMES->value => $this->bestTimesSection($brand),
                ReportSection::COMPETITORS->value => $this->competitors($brand, $days, $to, $providers),
                default => null,
            };
        }

        return $data;
    }

    /**
     * @return Collection<int, AccountMetricSnapshot>
     */
    private function accountSnapshots(Organization $organization, Brand $brand, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return AccountMetricSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->where('brand_id', $brand->id)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Publicaciones de la marca publicadas en el periodo (días de la marca), con
     * su red, su destino y su última medición.
     *
     * @return Collection<int, PublicationTarget>
     */
    private function publishedTargets(Organization $organization, Brand $brand, CarbonImmutable $from, CarbonImmutable $to, string $timezone): Collection
    {
        $start = $from->setTimezone($timezone)->startOfDay()->utc();
        $end = $to->setTimezone($timezone)->endOfDay()->utc();

        $targets = PublicationTarget::query()->withoutGlobalScope(OrganizationScope::class)
            ->join('post_variants as v', 'v.id', '=', 'publication_targets.post_variant_id')
            ->join('content_items as c', 'c.id', '=', 'v.content_item_id')
            ->where('publication_targets.organization_id', $organization->id)
            ->where('c.brand_id', $brand->id)
            ->whereNotNull('publication_targets.published_at')
            ->whereBetween('publication_targets.published_at', [$start, $end])
            ->orderBy('publication_targets.published_at')
            ->get(['publication_targets.*', 'v.provider as report_provider', 'c.title as report_title']);

        if ($targets->isEmpty()) {
            return $targets;
        }

        // Última medición de cada publicación (los snapshots guardan totales acumulados).
        $latest = PostMetricSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('publication_target_id', $targets->pluck('id'))
            ->selectRaw('publication_target_id, MAX(date) AS last_date')
            ->groupBy('publication_target_id');
        $metrics = PostMetricSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
            ->joinSub($latest, 'latest', function (JoinClause $join): void {
                $join->on('latest.publication_target_id', '=', 'post_metric_snapshots.publication_target_id')
                    ->on('latest.last_date', '=', 'post_metric_snapshots.date');
            })
            ->get(['post_metric_snapshots.*'])
            ->keyBy('publication_target_id');

        $destinations = SocialConnectionDestination::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('id', $targets->pluck('social_connection_destination_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($targets as $target) {
            $target->setAttribute('report_metrics', $metrics->get($target->id));
            $target->setAttribute('report_account', $destinations->get($target->social_connection_destination_id));
        }

        return $targets;
    }

    /**
     * @param  Collection<int, AccountMetricSnapshot>  $current
     * @param  Collection<int, AccountMetricSnapshot>  $previous
     * @param  Collection<int, PublicationTarget>  $published
     * @return array<string, mixed>
     */
    private function summary(Collection $current, Collection $previous, Collection $published, int $previousPosts): array
    {
        [$followers, $change, $start] = $this->followers($current);
        $engagement = (int) $current->sum('engagement');
        $reach = (int) $current->sum('reach');

        return [
            'followers' => $followers,
            'followers_change' => $change,
            'followers_change_pct' => $change !== null && $start > 0 ? round($change / $start * 100, 1) : null,
            'reach' => $this->kpi($reach, (int) $previous->sum('reach')),
            'impressions' => $this->kpi((int) $current->sum('impressions'), (int) $previous->sum('impressions')),
            'engagement' => $this->kpi($engagement, (int) $previous->sum('engagement')),
            'posts' => $this->kpi($published->count(), $previousPosts),
            'engagement_rate' => $reach > 0 ? round($engagement / $reach * 100, 2) : null,
        ];
    }

    /**
     * Seguidores al final del periodo, su cambio y el punto de partida (suma de
     * cuentas; cada una con su primera y última medición del periodo).
     *
     * @param  Collection<int, AccountMetricSnapshot>  $snapshots
     * @return array{0: int|null, 1: int|null, 2: int}
     */
    private function followers(Collection $snapshots): array
    {
        if ($snapshots->isEmpty()) {
            return [null, null, 0];
        }

        $end = 0;
        $start = 0;
        $comparable = false;
        foreach ($snapshots->groupBy('social_connection_destination_id') as $rows) {
            $end += (int) $rows->last()->followers;
            $start += (int) $rows->first()->followers;
            $comparable = $comparable || $rows->count() >= 2;
        }

        return [$end, $comparable ? $end - $start : null, $start];
    }

    /**
     * @return array{value: int, previous: int, change_pct: float|null}
     */
    private function kpi(int $value, int $previous): array
    {
        return [
            'value' => $value,
            'previous' => $previous,
            'change_pct' => $previous > 0 ? round(($value - $previous) / $previous * 100, 1) : null,
        ];
    }

    /**
     * @param  Collection<int, AccountMetricSnapshot>  $current
     * @param  Collection<int, PublicationTarget>  $published
     * @param  array<string, string>  $providers
     * @return list<array<string, mixed>>
     */
    private function channels(Collection $current, Collection $published, array $providers): array
    {
        $destinationIds = $current->pluck('social_connection_destination_id')
            ->merge($published->pluck('social_connection_destination_id'))
            ->filter()
            ->unique()
            ->values();
        if ($destinationIds->isEmpty()) {
            return [];
        }

        $destinations = SocialConnectionDestination::query()->withoutGlobalScope(OrganizationScope::class)
            ->with(['connection' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->whereIn('id', $destinationIds)
            ->get()
            ->keyBy('id');
        $byDestination = $current->groupBy('social_connection_destination_id');
        $postsByDestination = $published->countBy('social_connection_destination_id');

        $rows = [];
        foreach ($destinationIds as $id) {
            $destination = $destinations->get($id);
            /** @var Collection<int, AccountMetricSnapshot> $snapshots */
            $snapshots = $byDestination->get($id, collect());
            [$followers, $change, $start] = $this->followers($snapshots);
            $provider = $snapshots->first()->provider ?? $destination->connection->provider ?? 'unknown';
            $engagement = (int) $snapshots->sum('engagement');
            $reach = (int) $snapshots->sum('reach');

            $rows[] = [
                'provider' => $provider,
                'provider_label' => $providers[$provider] ?? ucfirst($provider),
                'account' => $destination->name ?? '—',
                'followers' => $followers,
                'followers_change' => $change,
                'followers_change_pct' => $change !== null && $start > 0 ? round($change / $start * 100, 1) : null,
                'reach' => $reach,
                'impressions' => (int) $snapshots->sum('impressions'),
                'engagement' => $engagement,
                'engagement_rate' => $reach > 0 ? round($engagement / $reach * 100, 2) : null,
                'posts' => (int) ($postsByDestination[$id] ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['provider_label'], $a['account']] <=> [$b['provider_label'], $b['account']]);

        return $rows;
    }

    /**
     * Seguidores totales por día (cada cuenta arrastra su última medición si un
     * día no se midió).
     *
     * @param  Collection<int, AccountMetricSnapshot>  $current
     * @return array{dates: list<string>, followers: list<int|null>}
     */
    private function growth(Collection $current, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDestination = [];
        foreach ($current as $snapshot) {
            $byDestination[$snapshot->social_connection_destination_id][Carbon::parse($snapshot->date)->toDateString()] = (int) $snapshot->followers;
        }

        $dates = [];
        $totals = [];
        $last = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $total = null;
            foreach ($byDestination as $destination => $values) {
                if (array_key_exists($date, $values)) {
                    $last[$destination] = $values[$date];
                }
                if (array_key_exists($destination, $last)) {
                    $total = ($total ?? 0) + $last[$destination];
                }
            }
            $dates[] = $date;
            $totals[] = $total;
        }

        return ['dates' => $dates, 'followers' => $totals];
    }

    /**
     * @param  Collection<int, PublicationTarget>  $published
     * @param  array<string, string>  $providers
     * @return list<array<string, mixed>>
     */
    private function topPosts(Collection $published, array $providers, string $timezone): array
    {
        return $published
            ->filter(fn (PublicationTarget $t): bool => $t->getAttribute('report_metrics') !== null)
            ->sortByDesc(fn (PublicationTarget $t): int => (int) $t->getAttribute('report_metrics')->engagement)
            ->take(self::TOP_POSTS)
            ->map(function (PublicationTarget $t) use ($providers, $timezone): array {
                /** @var PostMetricSnapshot $metrics */
                $metrics = $t->getAttribute('report_metrics');
                $provider = (string) $t->getAttribute('report_provider');

                return [
                    'title' => (string) $t->getAttribute('report_title'),
                    'provider' => $provider,
                    'provider_label' => $providers[$provider] ?? ucfirst($provider),
                    'account' => $t->getAttribute('report_account'),
                    'published_at' => $t->published_at?->setTimezone($timezone)->toIso8601String(),
                    'url' => $t->remote_url,
                    'impressions' => (int) $metrics->impressions,
                    'reach' => (int) $metrics->reach,
                    'likes' => (int) $metrics->likes,
                    'comments' => (int) $metrics->comments,
                    'shares' => (int) $metrics->shares,
                    'engagement' => (int) $metrics->engagement,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PublicationTarget>  $published
     * @param  array<string, string>  $providers
     * @return array<string, mixed>
     */
    private function publishing(Organization $organization, Brand $brand, Collection $published, array $providers, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $byProvider = [];
        foreach ($published->countBy(fn (PublicationTarget $t): string => (string) $t->getAttribute('report_provider')) as $provider => $count) {
            $byProvider[] = ['provider' => $provider, 'provider_label' => $providers[$provider] ?? ucfirst((string) $provider), 'posts' => $count];
        }
        usort($byProvider, fn (array $a, array $b): int => $b['posts'] <=> $a['posts']);

        $byWeekday = array_fill(1, 7, 0);
        foreach ($published as $target) {
            $byWeekday[(int) $target->published_at?->setTimezone($timezone)->isoWeekday()]++;
        }

        $failed = PublicationTarget::query()->withoutGlobalScope(OrganizationScope::class)
            ->join('post_variants as v', 'v.id', '=', 'publication_targets.post_variant_id')
            ->join('content_items as c', 'c.id', '=', 'v.content_item_id')
            ->where('publication_targets.organization_id', $organization->id)
            ->where('c.brand_id', $brand->id)
            ->where('publication_targets.status', TargetStatus::FAILED->value)
            ->whereBetween('publication_targets.scheduled_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->count();

        return [
            'published' => $published->count(),
            'failed' => $failed,
            'by_provider' => $byProvider,
            'by_weekday' => array_map(
                fn (int $weekday): array => ['weekday' => $weekday, 'label' => self::WEEKDAYS[$weekday], 'posts' => $byWeekday[$weekday]],
                array_keys(self::WEEKDAYS),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bestTimesSection(Brand $brand): array
    {
        $now = CarbonImmutable::now();
        $result = $this->bestTimes->forBrand($brand, [], $now, $now->addDays(7));

        return [
            'sufficient' => $result['sufficient'],
            'sample' => $result['sample'],
            'min_posts' => $result['min_posts'],
            'timezone' => $result['timezone'],
            'top' => array_map(fn (array $slot): array => [
                'weekday' => $slot['weekday'],
                'label' => self::WEEKDAYS[(int) $slot['weekday']] ?? '',
                'hour' => $slot['hour'],
                'lift' => $slot['lift'],
            ], $result['top']),
        ];
    }

    /**
     * @param  array<string, string>  $providers
     * @return array<string, mixed>
     */
    private function competitors(Brand $brand, int $days, CarbonImmutable $to, array $providers): array
    {
        $benchmark = $this->benchmark->build($brand, min($days, 365), null, $to);
        $rows = array_map(fn (array $row): array => [
            'kind' => $row['kind'],
            'name' => $row['name'],
            'provider' => $row['provider'],
            'provider_label' => $providers[$row['provider']] ?? ucfirst((string) $row['provider']),
            'account' => $row['account'],
            'followers' => $row['followers'],
            'followers_change' => $row['followers_change'],
            'followers_change_pct' => $row['followers_change_pct'],
            'posts' => $row['posts'],
            'avg_engagement' => $row['avg_engagement'],
            'engagement_rate' => $row['engagement_rate'],
        ], $benchmark['rows']);

        return [
            'has_competitors' => in_array('competitor', array_column($rows, 'kind'), true),
            'rows' => array_slice($rows, 0, 20),
        ];
    }
}
