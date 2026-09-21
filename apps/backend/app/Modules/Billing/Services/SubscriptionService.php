<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\PlanCatalog;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Organizations\Models\Organization;

class SubscriptionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    /**
     * Inicia el trial de una Organization en el plan por defecto.
     * Si no hay planes sembrados, no hace nada (degradación segura).
     */
    public function startTrial(Organization $organization): ?Subscription
    {
        if ($this->find($organization) !== null) {
            return null;
        }

        $plan = Plan::query()->where('key', PlanCatalog::defaultTrialPlan())->first();
        if ($plan === null) {
            return null;
        }

        $days = $plan->trial_days > 0 ? $plan->trial_days : PlanCatalog::TRIAL_DAYS;

        $subscription = Subscription::query()->create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::TRIALING->value,
            'interval' => 'month',
            'trial_ends_at' => now()->addDays($days),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
        ]);

        $this->entitlements->flush();
        $this->audit->log(
            AuditAction::SUBSCRIPTION_TRIAL_STARTED,
            $subscription,
            ['plan' => $plan->key, 'trial_days' => $days],
            organizationId: $organization->id,
        );

        return $subscription;
    }

    /**
     * Activa/cambia el plan de una Organization (tras checkout o alta manual).
     */
    public function activatePlan(
        Organization $organization,
        Plan $plan,
        string $interval,
        string $gateway,
        ?string $gatewaySubscriptionId = null,
        SubscriptionStatus $status = SubscriptionStatus::ACTIVE,
    ): Subscription {
        $subscription = $this->find($organization) ?? new Subscription(['organization_id' => $organization->id]);

        $periodEnd = $interval === 'year' ? now()->addYear() : now()->addMonth();

        $subscription->fill([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => $status->value,
            'interval' => $interval,
            'current_period_start' => now(),
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'gateway' => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
        ]);
        $subscription->save();

        $this->entitlements->flush();
        $this->audit->log(
            AuditAction::SUBSCRIPTION_CHANGED,
            $subscription,
            ['plan' => $plan->key, 'interval' => $interval, 'gateway' => $gateway],
            organizationId: $organization->id,
        );

        return $subscription;
    }

    public function cancel(Organization $organization, bool $atPeriodEnd = true): ?Subscription
    {
        $subscription = $this->find($organization);
        if ($subscription === null) {
            return null;
        }

        if ($atPeriodEnd) {
            $subscription->update(['cancel_at_period_end' => true]);
        } else {
            $subscription->update([
                'status' => SubscriptionStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'cancel_at_period_end' => false,
            ]);
        }

        $this->entitlements->flush();
        $this->audit->log(
            AuditAction::SUBSCRIPTION_CANCELLED,
            $subscription,
            ['at_period_end' => $atPeriodEnd],
            organizationId: $organization->id,
        );

        return $subscription;
    }

    public function find(Organization $organization): ?Subscription
    {
        return Subscription::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->first();
    }
}
