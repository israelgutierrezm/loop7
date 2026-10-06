<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Services;

use App\Modules\Analytics\Models\AccountMetricSnapshot;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Brands\Models\Brand;
use App\Modules\Competitors\Models\Competitor;
use App\Modules\Competitors\Models\CompetitorAccount;
use App\Modules\Competitors\Models\CompetitorPost;
use App\Modules\Competitors\Models\CompetitorSnapshot;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Comparación de la marca con su competencia en un periodo (docs/05):
 * seguidores y su variación, publicaciones, interacción media por publicación
 * («me gusta» + comentarios, lo comparable entre cuentas) y tasa de interacción
 * (interacción media / seguidores). Las cuentas propias salen de la analítica;
 * las de la competencia, de sus fotos diarias y publicaciones.
 */
final class CompetitorBenchmark
{
    /** Redes con datos de la competencia. */
    public const PROVIDERS = ['instagram', 'facebook', 'threads', 'fake'];

    private const TOP_POSTS = 10;

    /**
     * @return array<string, mixed>
     */
    public function build(Brand $brand, int $days, ?string $provider): array
    {
        $to = Carbon::today();
        $from = $to->copy()->subDays($days - 1);
        $providers = $provider !== null ? [$provider] : self::PROVIDERS;

        $rows = [
            ...$this->ownRows($brand, $from, $to, $providers),
            ...$this->competitorRows($brand, $from, $to, $providers),
        ];

        $dates = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return [
            'period' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows' => array_map(fn (array $row): array => array_diff_key($row, ['history' => true]), $rows),
            'series' => [
                'dates' => $dates,
                'lines' => array_map(fn (array $row): array => [
                    'key' => $row['key'],
                    'label' => $row['name'] . ' · ' . $row['account'],
                    'kind' => $row['kind'],
                    'provider' => $row['provider'],
                    'values' => array_map(fn (string $date): ?int => $row['history'][$date] ?? null, $dates),
                ], $rows),
            ],
            'top_posts' => $this->topPosts($brand, $from, $to, $providers),
        ];
    }

    /**
     * Cuentas propias de la marca en esas redes.
     *
     * @param  list<string>  $providers
     * @return list<array<string, mixed>>
     */
    private function ownRows(Brand $brand, Carbon $from, Carbon $to, array $providers): array
    {
        $connections = SocialConnection::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $brand->organization_id)
            ->where('brand_id', $brand->id)
            ->whereIn('provider', $providers)
            ->where('status', ConnectionStatus::CONNECTED->value)
            ->get()
            ->keyBy('id');
        if ($connections->isEmpty()) {
            return [];
        }

        $destinations = SocialConnectionDestination::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('social_connection_id', $connections->keys())
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($destinations as $destination) {
            $history = AccountMetricSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('social_connection_destination_id', $destination->id)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->orderBy('date')
                ->get()
                ->mapWithKeys(fn (AccountMetricSnapshot $s): array => [Carbon::parse($s->date)->toDateString() => $s->followers])
                ->all();

            $targets = PublicationTarget::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('social_connection_destination_id', $destination->id)
                ->whereNotNull('published_at')
                ->whereBetween('published_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->pluck('id');

            // Última medición de cada publicación del periodo.
            $engagement = PostMetricSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
                ->whereIn('publication_target_id', $targets)
                ->orderBy('date')
                ->get()
                ->groupBy('publication_target_id')
                ->map(fn (Collection $g): int => (int) $g->last()->likes + (int) $g->last()->comments)
                ->values()
                ->all();

            /** @var SocialConnection $connection */
            $connection = $connections->get($destination->social_connection_id);
            $rows[] = $this->row(
                key: 'own:' . $destination->public_id,
                kind: 'own',
                name: $brand->name,
                provider: $connection->provider,
                account: $destination->name,
                history: $history,
                posts: $targets->count(),
                engagements: $engagement,
                extra: ['competitor' => null],
            );
        }

        return $rows;
    }

    /**
     * Cuentas de los competidores de la marca en esas redes.
     *
     * @param  list<string>  $providers
     * @return list<array<string, mixed>>
     */
    private function competitorRows(Brand $brand, Carbon $from, Carbon $to, array $providers): array
    {
        $competitors = Competitor::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $brand->organization_id)
            ->where('brand_id', $brand->id)
            ->orderBy('name')
            ->get()
            ->keyBy('id');
        if ($competitors->isEmpty()) {
            return [];
        }

        $accounts = CompetitorAccount::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('competitor_id', $competitors->keys())
            ->whereIn('provider', $providers)
            ->get()
            ->sortBy(fn (CompetitorAccount $a): string => $competitors->get($a->competitor_id)?->name . '|' . $a->provider . '|' . $a->handle);

        $rows = [];
        foreach ($accounts as $account) {
            $snapshots = CompetitorSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('competitor_account_id', $account->id)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->orderBy('date')
                ->get();

            $posts = CompetitorPost::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('competitor_account_id', $account->id)
                ->whereBetween('published_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->get();

            /** @var Competitor $competitor */
            $competitor = $competitors->get($account->competitor_id);
            $rows[] = $this->row(
                key: 'competitor:' . $account->public_id,
                kind: 'competitor',
                name: $competitor->name,
                provider: $account->provider,
                account: '@' . $account->handle,
                history: $snapshots->mapWithKeys(fn (CompetitorSnapshot $s): array => [$s->date->toDateString() => $s->followers])->all(),
                // Sin publicaciones en la red (Facebook, Threads) no se puede comparar.
                posts: in_array($account->provider, ['facebook', 'threads'], true) ? null : $posts->count(),
                engagements: $posts->map(fn (CompetitorPost $p): ?int => $p->engagement())->filter(fn (?int $e): bool => $e !== null)->values()->all(),
                extra: [
                    'competitor' => $competitor->public_id,
                    'account_id' => $account->public_id,
                    'display_name' => $account->display_name,
                    'avatar_url' => $account->avatar_url,
                    'profile_url' => $account->profile_url,
                    'status' => $account->status,
                    'last_error' => $account->last_error,
                    'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                    'weekly' => $snapshots->last()?->weekly,
                ],
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, int|null>  $history  fecha => seguidores
     * @param  list<int>  $engagements  «me gusta» + comentarios de cada publicación del periodo
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(string $key, string $kind, string $name, string $provider, string $account, array $history, ?int $posts, array $engagements, array $extra): array
    {
        $values = array_values(array_filter($history, fn (?int $v): bool => $v !== null));
        $followers = $values !== [] ? $values[count($values) - 1] : null;
        $change = count($values) >= 2 ? $values[count($values) - 1] - $values[0] : null;
        $average = $engagements !== [] ? round(array_sum($engagements) / count($engagements), 1) : null;

        return [
            'key' => $key,
            'kind' => $kind,
            'name' => $name,
            'provider' => $provider,
            'account' => $account,
            'followers' => $followers,
            'followers_change' => $change,
            'followers_change_pct' => $change !== null && $values[0] > 0 ? round($change / $values[0] * 100, 2) : null,
            'posts' => $posts,
            'avg_engagement' => $average,
            'engagement_rate' => $average !== null && $followers !== null && $followers > 0 ? round($average / $followers * 100, 2) : null,
            ...$extra,
            'history' => $history,
        ];
    }

    /**
     * Publicaciones de la competencia con más interacción en el periodo.
     *
     * @param  list<string>  $providers
     * @return list<array<string, mixed>>
     */
    private function topPosts(Brand $brand, Carbon $from, Carbon $to, array $providers): array
    {
        $accounts = CompetitorAccount::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('provider', $providers)
            ->whereIn('competitor_id', Competitor::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $brand->organization_id)
                ->where('brand_id', $brand->id)
                ->select('id'))
            ->with(['competitor' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->get()
            ->keyBy('id');
        if ($accounts->isEmpty()) {
            return [];
        }

        return CompetitorPost::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('competitor_account_id', $accounts->keys())
            ->whereBetween('published_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(fn ($q) => $q->whereNotNull('likes')->orWhereNotNull('comments'))
            ->orderByRaw('COALESCE(likes, 0) + COALESCE(comments, 0) DESC')
            ->limit(self::TOP_POSTS)
            ->get()
            ->map(function (CompetitorPost $post) use ($accounts): array {
                /** @var CompetitorAccount $account */
                $account = $accounts->get($post->competitor_account_id);

                return [
                    'competitor' => $account->competitor?->name,
                    'provider' => $account->provider,
                    'handle' => $account->handle,
                    'type' => $post->type,
                    'caption' => $post->caption,
                    'permalink' => $post->permalink,
                    'thumbnail_url' => $post->thumbnail_url,
                    'published_at' => $post->published_at?->toIso8601String(),
                    'likes' => $post->likes,
                    'comments' => $post->comments,
                    'views' => $post->views,
                    'engagement' => $post->engagement(),
                ];
            })
            ->all();
    }
}
