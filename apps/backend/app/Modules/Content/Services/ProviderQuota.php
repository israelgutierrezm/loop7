<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Contracts\HasPlanQuota;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Support\Carbon;

/**
 * Cupos por red del plan (docs/08): publicaciones en X al mes y subidas a
 * YouTube al día. Al programar cuentan las publicadas y las ya programadas en
 * el periodo de la fecha elegida; al publicar, sólo las publicadas.
 */
final class ProviderQuota
{
    public function __construct(
        private readonly SocialProviderManager $manager,
        private readonly EntitlementsService $entitlements,
        private readonly UsageService $usage,
    ) {
    }

    /**
     * Lanza 402 si las nuevas publicaciones no caben en el cupo del periodo.
     */
    public function ensureCanSchedule(Organization $organization, string $provider, Carbon $when, int $adding): void
    {
        $quota = $this->quota($provider);
        if ($quota === null || $adding <= 0) {
            return;
        }

        [$from, $to] = $this->bounds($quota['period'], $when);
        $this->usage->ensureWithin(
            $organization,
            $quota['entitlement'],
            $this->usage->providerPublications($organization, $provider, $from, $to),
            $adding,
            $this->message($organization, $quota) . ' Elige otra fecha o amplía el plan.',
        );
    }

    /**
     * Lanza 402 si ya se agotó el cupo del periodo en curso.
     */
    public function ensureCanPublish(Organization $organization, string $provider): void
    {
        $quota = $this->quota($provider);
        if ($quota === null) {
            return;
        }

        [$from, $to] = $this->bounds($quota['period'], Carbon::now());
        $this->usage->ensureWithin(
            $organization,
            $quota['entitlement'],
            $this->usage->providerPublications($organization, $provider, $from, $to, includeScheduled: false),
            1,
            $this->message($organization, $quota) . ' Se alcanzó: amplía el plan o publica en el siguiente periodo.',
        );
    }

    /**
     * @return array{entitlement: string, period: 'day'|'month', label: string}|null
     */
    private function quota(string $provider): ?array
    {
        $adapter = $this->manager->adapter($provider);

        return $adapter instanceof HasPlanQuota ? $adapter->planQuota() : null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(string $period, Carbon $at): array
    {
        return $period === 'day'
            ? [$at->copy()->startOfDay(), $at->copy()->endOfDay()]
            : [$at->copy()->startOfMonth(), $at->copy()->endOfMonth()];
    }

    /**
     * @param  array{entitlement: string, period: 'day'|'month', label: string}  $quota
     */
    private function message(Organization $organization, array $quota): string
    {
        return 'Tu plan permite ' . $this->entitlements->limit($organization, $quota['entitlement']) . ' ' . $quota['label'] . '.';
    }
}
