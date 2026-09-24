<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Models\Invoice;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas del negocio y de la operación para SUPERADMIN (docs/09).
 */
class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $since30 = Carbon::now()->subDays(30);
        $since7 = Carbon::now()->subDays(7);

        $byStatus = Subscription::query()->withoutGlobalScopes()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $byPlan = Subscription::query()->withoutGlobalScopes()
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', [SubscriptionStatus::TRIALING->value, SubscriptionStatus::ACTIVE->value, SubscriptionStatus::GRACE->value])
            ->selectRaw('plans.name as plan, count(*) as total')
            ->groupBy('plans.name')
            ->orderByDesc('total')
            ->pluck('total', 'plan');

        return ApiResponse::success([
            'organizations' => [
                'total' => Organization::query()->count(),
                'active' => Organization::query()->where('status', OrganizationStatus::ACTIVE->value)->count(),
                'suspended' => Organization::query()->where('status', OrganizationStatus::SUSPENDED->value)->count(),
                'new_last_30_days' => Organization::query()->where('created_at', '>=', $since30)->count(),
            ],
            'users' => [
                'total' => User::query()->count(),
                'platform_admins' => User::query()->where('is_platform_admin', true)->count(),
                'new_last_7_days' => User::query()->where('created_at', '>=', $since7)->count(),
            ],
            'brands' => ['total' => Brand::query()->withoutGlobalScopes()->count()],
            'subscriptions' => [
                'by_status' => $byStatus,
                'by_plan' => $byPlan,
                'trials_ending_7_days' => Subscription::query()->withoutGlobalScopes()
                    ->where('status', SubscriptionStatus::TRIALING->value)
                    ->whereBetween('trial_ends_at', [Carbon::now(), Carbon::now()->addDays(7)])
                    ->count(),
            ],
            'revenue' => [
                'mrr' => $this->mrr(),
                'last_30_days' => Invoice::query()->withoutGlobalScopes()
                    ->where('status', Invoice::PAID)->where('paid_at', '>=', $since30)
                    ->selectRaw('currency, sum(amount_cents) as total')->groupBy('currency')
                    ->pluck('total', 'currency'),
                'conversions_30_days' => AuditLog::query()->where('action', AuditAction::SUBSCRIPTION_CHANGED)
                    ->where('created_at', '>=', $since30)->count(),
                // Suscripciones que perdieron el acceso en los últimos 30 días.
                'churn_30_days' => Subscription::query()->withoutGlobalScopes()
                    ->whereIn('status', [
                        SubscriptionStatus::CANCELLED->value,
                        SubscriptionStatus::EXPIRED->value,
                        SubscriptionStatus::SUSPENDED->value,
                    ])
                    ->where('updated_at', '>=', $since30)
                    ->count(),
                'open_invoices' => Invoice::query()->withoutGlobalScopes()->where('status', Invoice::OPEN)->count(),
                'failed_payments_30_days' => PaymentTransaction::query()
                    ->where('status', 'failed')->where('created_at', '>=', $since30)->count(),
            ],
            'publishing' => [
                'published_7_days' => PublicationTarget::query()->withoutGlobalScopes()
                    ->where('status', TargetStatus::PUBLISHED->value)->where('published_at', '>=', $since7)->count(),
                'failed_7_days' => PublicationTarget::query()->withoutGlobalScopes()
                    ->where('status', TargetStatus::FAILED->value)->where('updated_at', '>=', $since7)->count(),
                'scheduled' => PublicationTarget::query()->withoutGlobalScopes()
                    ->where('status', TargetStatus::SCHEDULED->value)->count(),
            ],
            'ai' => [
                'credits_this_month' => (int) DB::table('usage_counters')
                    ->where('key', 'ai_credits.month')->where('period', Carbon::now()->format('Y-m'))->sum('used'),
                'generations_30_days' => DB::table('ai_usage_logs')->where('created_at', '>=', $since30)->count(),
            ],
            'operations' => [
                'queued_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
                'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
                'failed_webhooks' => PaymentWebhookEvent::query()->where('status', 'failed')->count(),
                'social_needing_attention' => SocialConnection::query()->withoutGlobalScopes()
                    ->whereIn('status', [ConnectionStatus::EXPIRED->value, ConnectionStatus::ERROR->value])->count(),
            ],
            'audit' => [
                'events_today' => AuditLog::query()->whereDate('created_at', today())->count(),
            ],
            'recent_organizations' => Organization::query()->latest()->take(5)->get()->map(fn (Organization $o) => [
                'id' => $o->public_id,
                'name' => $o->name,
                'status' => $o->status->value,
                'created_at' => $o->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Ingreso mensual recurrente estimado: por cada suscripción activa, el último
     * cobro de su plan normalizado a un mes (anual / 12), agrupado por moneda.
     * Las suscripciones de cortesía (sin cobros) no suman.
     *
     * @return array<string, int>
     */
    private function mrr(): array
    {
        $totals = [];

        Subscription::query()->withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::GRACE->value])
            ->each(function (Subscription $subscription) use (&$totals): void {
                $invoice = Invoice::query()->withoutGlobalScopes()
                    ->where('subscription_id', $subscription->id)
                    ->where('status', Invoice::PAID)
                    ->latest('paid_at')
                    ->first();
                if ($invoice === null) {
                    return;
                }
                $monthly = $subscription->interval === 'year'
                    ? (int) round($invoice->amount_cents / 12)
                    : $invoice->amount_cents;
                $totals[$invoice->currency] = ($totals[$invoice->currency] ?? 0) + $monthly;
            });

        return $totals;
    }
}
