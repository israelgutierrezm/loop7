<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Models\OrganizationAddOn;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\UsageCounter;
use App\Modules\Organizations\Models\Organization;

/**
 * Resuelve y evalúa los entitlements (límites y features) de una Organization
 * a partir de su suscripción y add-ons. Es la fuente de verdad para aplicar
 * límites de plan en el backend (docs/08 y docs/15).
 */
class EntitlementsService
{
    /** @var array<int, array<string, int|bool>> memo por organization_id */
    private array $cache = [];

    /**
     * @return array<string, int|bool>
     */
    public function forOrganization(Organization $organization): array
    {
        if (isset($this->cache[$organization->id])) {
            return $this->cache[$organization->id];
        }

        // Valores base: sin plan, todo a 0 / false.
        $values = [];
        foreach (Entitlement::all() as $key) {
            $values[$key] = Entitlement::isBool($key) ? false : 0;
        }

        $subscription = Subscription::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->first();

        if ($subscription !== null && $subscription->grantsAccess() && $subscription->plan_id !== null) {
            $plan = Plan::query()->with('entitlements')->find($subscription->plan_id);
            if ($plan !== null) {
                foreach ($plan->entitlements as $planEntitlement) {
                    $values[$planEntitlement->entitlement_key] = $this->castValue(
                        $planEntitlement->entitlement_key,
                        $planEntitlement->value,
                    );
                }
            }
        }

        // Add-ons: suman a los límites (no aplican a features booleanas).
        $addOns = OrganizationAddOn::query()
            ->withoutGlobalScopes()
            ->with('addOn')
            ->where('organization_id', $organization->id)
            ->get();

        foreach ($addOns as $orgAddOn) {
            $key = $orgAddOn->addOn->entitlement_key;
            $current = $values[$key] ?? 0;
            if (! Entitlement::isBool($key) && is_int($current) && $current !== Entitlement::UNLIMITED) {
                $values[$key] = $current + ($orgAddOn->addOn->quantity_per_unit * $orgAddOn->quantity);
            }
        }

        return $this->cache[$organization->id] = $values;
    }

    public function limit(Organization $organization, string $key): int
    {
        $value = $this->forOrganization($organization)[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    public function allows(Organization $organization, string $key): bool
    {
        return (bool) ($this->forOrganization($organization)[$key] ?? false);
    }

    public function isUnlimited(Organization $organization, string $key): bool
    {
        return $this->limit($organization, $key) === Entitlement::UNLIMITED;
    }

    /**
     * ¿El uso actual (count) está por debajo del límite del plan?
     */
    public function withinLimit(Organization $organization, string $key, int $current): bool
    {
        $limit = $this->limit($organization, $key);

        if ($limit === Entitlement::UNLIMITED) {
            return true;
        }

        return $current < $limit;
    }

    public function usage(Organization $organization, string $key, string $period): int
    {
        return (int) (UsageCounter::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('key', $key)
            ->where('period', $period)
            ->value('used') ?? 0);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function castValue(string $key, string $value): int|bool
    {
        if (Entitlement::isBool($key)) {
            return $value === '1' || $value === 'true';
        }

        return (int) $value;
    }
}
