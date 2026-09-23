<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Jobs\SyncAccountMetrics;
use App\Modules\Analytics\Jobs\SyncPostMetrics;
use App\Modules\Analytics\Models\AccountMetricSnapshot;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sincroniza métricas desde los proveedores hacia snapshots diarios (docs/05).
 * Cada destino/post se resuelve en el contexto de su Organization/Brand; los
 * proveedores sin configurar se omiten con elegancia (no rompen la sincronización).
 */
class MetricsSyncService
{
    public function __construct(private readonly SocialProviderManager $manager)
    {
    }

    public function syncAccount(SocialConnectionDestination $destination, ?Carbon $date = null): ?AccountMetricSnapshot
    {
        $date ??= Carbon::today();
        $connection = SocialConnection::query()->withoutGlobalScopes()->find($destination->social_connection_id);
        if ($connection === null || $connection->status !== ConnectionStatus::CONNECTED) {
            return null;
        }

        $adapter = $this->manager->adapter($connection->provider);
        if ($adapter === null) {
            return null;
        }

        $credentials = $this->manager->record($connection->provider)?->credentialMap() ?? [];

        try {
            $metrics = $adapter->fetchAccountMetrics($connection->toTokens(), $destination->external_id, $credentials);
        } catch (Throwable) {
            // Un proveedor sin configurar o con error puntual no debe romper la sync.
            return null;
        }

        return AccountMetricSnapshot::query()->updateOrCreate(
            ['social_connection_destination_id' => $destination->id, 'date' => $date->toDateString()],
            [
                'organization_id' => $connection->organization_id,
                'brand_id' => $connection->brand_id,
                'provider' => $connection->provider,
                'followers' => $metrics->followers,
                'reach' => $metrics->reach,
                'impressions' => $metrics->impressions,
                'engagement' => $metrics->engagement,
                'posts_count' => $metrics->postsCount,
            ],
        );
    }

    public function syncPost(PublicationTarget $target, ?Carbon $date = null): ?PostMetricSnapshot
    {
        $date ??= Carbon::today();
        if ($target->status !== TargetStatus::PUBLISHED || $target->remote_id === null) {
            return null;
        }

        $destination = SocialConnectionDestination::query()->withoutGlobalScopes()
            ->find($target->social_connection_destination_id);
        if ($destination === null) {
            return null;
        }

        $connection = SocialConnection::query()->withoutGlobalScopes()->find($destination->social_connection_id);
        if ($connection === null) {
            return null;
        }

        $adapter = $this->manager->adapter($connection->provider);
        if ($adapter === null) {
            return null;
        }

        $credentials = $this->manager->record($connection->provider)?->credentialMap() ?? [];

        try {
            $metrics = $adapter->fetchPostMetrics($connection->toTokens(), $target->remote_id, $credentials);
        } catch (Throwable) {
            return null;
        }

        return PostMetricSnapshot::query()->updateOrCreate(
            ['publication_target_id' => $target->id, 'date' => $date->toDateString()],
            [
                'organization_id' => $connection->organization_id,
                'brand_id' => $connection->brand_id,
                'provider' => $connection->provider,
                'remote_id' => $target->remote_id,
                'impressions' => $metrics->impressions,
                'reach' => $metrics->reach,
                'likes' => $metrics->likes,
                'comments' => $metrics->comments,
                'shares' => $metrics->shares,
                'clicks' => $metrics->clicks,
                'engagement' => $metrics->engagement(),
            ],
        );
    }

    /**
     * Sincroniza (en el acto) todas las cuentas y posts publicados de una Brand.
     *
     * @return array{accounts: int, posts: int}
     */
    public function syncBrand(Brand $brand): array
    {
        $accounts = 0;
        $posts = 0;

        foreach ($this->brandDestinations($brand) as $destination) {
            if ($this->syncAccount($destination) !== null) {
                $accounts++;
            }
        }

        foreach ($this->brandPublishedTargets($brand) as $target) {
            if ($this->syncPost($target) !== null) {
                $posts++;
            }
        }

        return ['accounts' => $accounts, 'posts' => $posts];
    }

    /**
     * Despacha jobs de sincronización para todos los destinos conectados y los
     * posts publicados recientemente (scheduler diario).
     */
    public function syncDue(): int
    {
        $dispatched = 0;

        SocialConnectionDestination::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas('connection', fn ($q) => $q->where('status', ConnectionStatus::CONNECTED->value))
            ->pluck('id')
            ->each(function (int $id) use (&$dispatched): void {
                SyncAccountMetrics::dispatch($id);
                $dispatched++;
            });

        PublicationTarget::query()->withoutGlobalScopes()
            ->where('status', TargetStatus::PUBLISHED->value)
            ->whereNotNull('remote_id')
            ->where('published_at', '>=', Carbon::now()->subDays(30))
            ->pluck('id')
            ->each(function (int $id) use (&$dispatched): void {
                SyncPostMetrics::dispatch($id);
                $dispatched++;
            });

        return $dispatched;
    }

    /**
     * Genera una serie histórica sintética para una Brand (datos de muestra en
     * desarrollo/demostración; sólo destinos con adaptador disponible).
     */
    public function backfillDemo(Brand $brand, int $days = 14): int
    {
        $created = 0;

        foreach ($this->brandDestinations($brand) as $destination) {
            for ($d = 0; $d < $days; $d++) {
                $date = Carbon::today()->subDays($d);
                $base = $this->syncAccount($destination, $date);
                if ($base === null) {
                    break; // proveedor no disponible: no insistir con este destino
                }
                // Tendencia ascendente hacia hoy + variación diaria determinista.
                $factor = 1 - ($d * 0.012);
                $jitter = (crc32($destination->external_id . $date->toDateString()) % 20) / 100; // 0..0.19
                $base->update([
                    'followers' => (int) round($base->followers * $factor),
                    'reach' => (int) round($base->reach * ($factor + $jitter)),
                    'impressions' => (int) round($base->impressions * ($factor + $jitter)),
                    'engagement' => (int) round($base->engagement * ($factor + $jitter)),
                ]);
                $created++;
            }
        }

        foreach ($this->brandPublishedTargets($brand) as $target) {
            if ($this->syncPost($target) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SocialConnectionDestination>
     */
    private function brandDestinations(Brand $brand): \Illuminate\Support\Collection
    {
        $connectionIds = SocialConnection::query()->withoutGlobalScopes()
            ->where('brand_id', $brand->id)
            ->where('status', ConnectionStatus::CONNECTED->value)
            ->pluck('id');

        return SocialConnectionDestination::query()->withoutGlobalScopes()
            ->whereIn('social_connection_id', $connectionIds)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, PublicationTarget>
     */
    private function brandPublishedTargets(Brand $brand): \Illuminate\Support\Collection
    {
        $connectionIds = SocialConnection::query()->withoutGlobalScopes()
            ->where('brand_id', $brand->id)
            ->pluck('id');
        $destinationIds = SocialConnectionDestination::query()->withoutGlobalScopes()
            ->whereIn('social_connection_id', $connectionIds)
            ->pluck('id');

        return PublicationTarget::query()->withoutGlobalScopes()
            ->whereIn('social_connection_destination_id', $destinationIds)
            ->where('status', TargetStatus::PUBLISHED->value)
            ->whereNotNull('remote_id')
            ->get();
    }
}
