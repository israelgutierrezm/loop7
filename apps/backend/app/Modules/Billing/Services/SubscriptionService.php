<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Events\SubscriptionChanged;
use App\Modules\Billing\Events\TrialEndingSoon;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Organizations\Models\Organization;
use App\Modules\PlatformAdmin\Services\PlatformSettings;
use Illuminate\Support\Carbon;

/**
 * Ciclo de vida de la suscripción de una Organization (docs/08): trial, alta de
 * plan, renovación, periodo de gracia, suspensión, expiración y cancelación.
 */
class SubscriptionService
{
    /** Días de antelación del aviso de fin de prueba. */
    public const TRIAL_REMINDER_DAYS = 3;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementsService $entitlements,
        private readonly PlatformSettings $settings,
    ) {
    }

    /**
     * Inicia el trial de una Organization en el plan de prueba configurado.
     * Si no hay planes sembrados, no hace nada (degradación segura).
     */
    public function startTrial(Organization $organization): ?Subscription
    {
        if ($this->find($organization) !== null) {
            return null;
        }

        $plan = Plan::query()->where('key', $this->settings->string('billing.trial_plan'))->where('is_active', true)->first()
            ?? Plan::query()->where('is_active', true)->orderBy('sort_order')->first();
        if ($plan === null) {
            return null;
        }

        $days = max(1, $plan->trial_days > 0 ? $plan->trial_days : $this->settings->int('billing.trial_days'));

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
     * Activa/cambia el plan de una Organization (tras un pago confirmado o un alta
     * manual de SUPERADMIN). Abre un periodo nuevo desde ahora.
     */
    public function activatePlan(
        Organization $organization,
        Plan $plan,
        string $interval,
        string $gateway,
        ?string $gatewaySubscriptionId = null,
        ?Carbon $periodEnd = null,
    ): Subscription {
        $subscription = $this->find($organization) ?? new Subscription(['organization_id' => $organization->id]);

        $subscription->forceFill([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'interval' => $interval,
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => $periodEnd ?? $this->periodEndFrom(now(), $interval),
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'gateway' => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
        ])->save();

        $this->changed($subscription, AuditAction::SUBSCRIPTION_CHANGED, [
            'plan' => $plan->key, 'interval' => $interval, 'gateway' => $gateway,
        ]);

        return $subscription;
    }

    /**
     * Cobro recurrente confirmado: extiende el periodo y reactiva la suscripción.
     */
    public function renew(Subscription $subscription, ?Carbon $periodEnd = null): Subscription
    {
        $from = $subscription->current_period_end !== null && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end
            : now();

        $subscription->forceFill([
            'status' => SubscriptionStatus::ACTIVE->value,
            'current_period_start' => now(),
            'current_period_end' => $periodEnd ?? $this->periodEndFrom($from->copy(), (string) ($subscription->interval ?? 'month')),
        ])->save();

        $this->changed($subscription, AuditAction::SUBSCRIPTION_RENEWED, ['until' => $subscription->current_period_end?->toIso8601String()]);

        return $subscription;
    }

    /**
     * Pago no recibido: mantiene el acceso durante los días de gracia configurados.
     */
    public function enterGrace(Subscription $subscription, string $reason): void
    {
        if ($subscription->status === SubscriptionStatus::GRACE) {
            return;
        }

        $subscription->forceFill(['status' => SubscriptionStatus::GRACE->value])->save();
        $this->changed($subscription, AuditAction::SUBSCRIPTION_PAYMENT_FAILED, ['reason' => $reason]);
    }

    public function cancel(Organization $organization, bool $atPeriodEnd = true): ?Subscription
    {
        $subscription = $this->find($organization);
        if ($subscription === null) {
            return null;
        }

        if ($atPeriodEnd) {
            $subscription->forceFill(['cancel_at_period_end' => true])->save();
        } else {
            $subscription->forceFill([
                'status' => SubscriptionStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'cancel_at_period_end' => false,
            ])->save();
        }

        $this->changed($subscription, AuditAction::SUBSCRIPTION_CANCELLED, ['at_period_end' => $atPeriodEnd]);

        return $subscription;
    }

    /**
     * Revierte una cancelación programada al final del periodo.
     */
    public function resume(Subscription $subscription): Subscription
    {
        $subscription->forceFill(['cancel_at_period_end' => false])->save();
        $this->changed($subscription, AuditAction::SUBSCRIPTION_RESUMED);

        return $subscription;
    }

    /**
     * La pasarela canceló la suscripción: termina el acceso de inmediato.
     */
    public function markCancelled(Subscription $subscription): void
    {
        $subscription->forceFill([
            'status' => SubscriptionStatus::CANCELLED->value,
            'cancelled_at' => now(),
            'cancel_at_period_end' => false,
        ])->save();

        $this->changed($subscription, AuditAction::SUBSCRIPTION_CANCELLED, ['by_gateway' => true]);
    }

    /**
     * Amplía el trial (SUPERADMIN).
     */
    public function extendTrial(Subscription $subscription, int $days): Subscription
    {
        $base = $subscription->trial_ends_at !== null && $subscription->trial_ends_at->isFuture()
            ? $subscription->trial_ends_at
            : now();
        $ends = $base->copy()->addDays($days);

        $subscription->forceFill([
            'status' => SubscriptionStatus::TRIALING->value,
            'trial_ends_at' => $ends,
            'current_period_end' => $ends,
            'cancelled_at' => null,
            'cancel_at_period_end' => false,
            'trial_reminder_sent_at' => null, // se volverá a avisar antes del nuevo fin
        ])->save();

        $this->changed($subscription, AuditAction::SUBSCRIPTION_CHANGED, ['trial_extended_days' => $days]);

        return $subscription;
    }

    /**
     * Aplica las transiciones por tiempo (se ejecuta cada hora):
     * trial vencido → expirada; fin de periodo con cancelación → cancelada;
     * fin de periodo sin renovación → gracia; gracia agotada → suspendida.
     *
     * @return array<string, int>
     */
    public function syncDue(): array
    {
        $grace = max(0, $this->settings->int('billing.grace_days'));
        $counts = ['trial_reminders' => 0, 'expired' => 0, 'cancelled' => 0, 'grace' => 0, 'suspended' => 0];

        // Aviso único de que la prueba termina pronto.
        Subscription::query()->withoutGlobalScopes()
            ->where('status', SubscriptionStatus::TRIALING->value)
            ->whereNull('trial_reminder_sent_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays(self::TRIAL_REMINDER_DAYS))
            ->each(function (Subscription $s) use (&$counts): void {
                $s->forceFill(['trial_reminder_sent_at' => now()])->save();
                TrialEndingSoon::dispatch($s);
                $counts['trial_reminders']++;
            });

        Subscription::query()->withoutGlobalScopes()
            ->where('status', SubscriptionStatus::TRIALING->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->each(function (Subscription $s) use (&$counts): void {
                $s->forceFill(['status' => SubscriptionStatus::EXPIRED->value])->save();
                $this->changed($s, AuditAction::SUBSCRIPTION_EXPIRED, ['reason' => 'trial_ended']);
                $counts['expired']++;
            });

        Subscription::query()->withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::GRACE->value])
            ->where('cancel_at_period_end', true)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->each(function (Subscription $s) use (&$counts): void {
                $s->forceFill([
                    'status' => SubscriptionStatus::CANCELLED->value,
                    'cancelled_at' => now(),
                    'cancel_at_period_end' => false,
                ])->save();
                $this->changed($s, AuditAction::SUBSCRIPTION_CANCELLED, ['reason' => 'period_ended']);
                $counts['cancelled']++;
            });

        Subscription::query()->withoutGlobalScopes()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('cancel_at_period_end', false)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->each(function (Subscription $s) use (&$counts): void {
                $this->enterGrace($s, 'period_ended_without_payment');
                $counts['grace']++;
            });

        Subscription::query()->withoutGlobalScopes()
            ->where('status', SubscriptionStatus::GRACE->value)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now()->subDays($grace))
            ->each(function (Subscription $s) use (&$counts): void {
                $s->forceFill(['status' => SubscriptionStatus::SUSPENDED->value])->save();
                $this->changed($s, AuditAction::SUBSCRIPTION_SUSPENDED, ['reason' => 'grace_period_ended']);
                $counts['suspended']++;
            });

        return $counts;
    }

    public function find(Organization $organization): ?Subscription
    {
        return Subscription::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->first();
    }

    public function findByGatewaySubscription(string $gateway, string $gatewaySubscriptionId): ?Subscription
    {
        return Subscription::query()
            ->withoutGlobalScopes()
            ->where('gateway', $gateway)
            ->where('gateway_subscription_id', $gatewaySubscriptionId)
            ->first();
    }

    public function periodEndFrom(Carbon $from, string $interval): Carbon
    {
        return $interval === 'year' ? $from->copy()->addYear() : $from->copy()->addMonth();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function changed(Subscription $subscription, string $action, array $properties = []): void
    {
        $this->entitlements->flush();
        $this->audit->log($action, $subscription, $properties, organizationId: $subscription->organization_id);

        SubscriptionChanged::dispatch($subscription, $action, $properties);
    }
}
