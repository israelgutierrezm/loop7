<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Models\UsageCounter;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * Gestiona los créditos de IA mensuales de una Organization: los límites vienen
 * del plan (entitlement ai_credits.month) y el consumo se acumula en
 * usage_counters por periodo 'YYYY-MM' (docs/07 y docs/08).
 */
class AiCreditService
{
    public function __construct(private readonly EntitlementsService $entitlements)
    {
    }

    public function period(): string
    {
        return Carbon::now()->format('Y-m');
    }

    public function limit(Organization $organization): int
    {
        return $this->entitlements->limit($organization, Entitlement::AI_CREDITS_MONTH);
    }

    public function isUnlimited(Organization $organization): bool
    {
        return $this->entitlements->isUnlimited($organization, Entitlement::AI_CREDITS_MONTH);
    }

    public function used(Organization $organization): int
    {
        return $this->entitlements->usage($organization, Entitlement::AI_CREDITS_MONTH, $this->period());
    }

    public function remaining(Organization $organization): int
    {
        if ($this->isUnlimited($organization)) {
            return Entitlement::UNLIMITED;
        }

        return max(0, $this->limit($organization) - $this->used($organization));
    }

    /**
     * Verifica que la Organization puede consumir `credits`. Lanza 402 si no.
     */
    public function ensureCanConsume(Organization $organization, int $credits): void
    {
        if ($credits <= 0 || $this->isUnlimited($organization)) {
            return;
        }

        $projected = $this->used($organization) + $credits;
        if ($projected > $this->limit($organization)) {
            throw new PlanLimitExceededException(
                'Has agotado tus créditos de IA de este mes.',
                Entitlement::AI_CREDITS_MONTH,
            );
        }
    }

    /**
     * Acumula el consumo de créditos del periodo actual de forma idempotente
     * respecto al contador (incrementa la fila única org+key+periodo).
     */
    public function consume(Organization $organization, int $credits): void
    {
        if ($credits <= 0 || $this->isUnlimited($organization)) {
            return;
        }

        $counter = UsageCounter::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'key' => Entitlement::AI_CREDITS_MONTH,
                'period' => $this->period(),
            ],
            ['used' => 0],
        );

        $counter->increment('used', $credits);
    }
}
