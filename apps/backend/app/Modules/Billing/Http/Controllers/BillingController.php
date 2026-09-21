<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Payments\Exceptions\GatewayNotImplementedException;
use App\Modules\Payments\Services\GatewayManager;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly SubscriptionService $subscriptions,
        private readonly GatewayManager $gateways,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * Catálogo público de planes (para la página de precios).
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::query()
            ->with(['prices', 'entitlements'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => $this->presentPlan($plan))
            ->all();

        return ApiResponse::success($plans);
    }

    /**
     * Pasarelas de pago habilitadas (nunca se listan las deshabilitadas).
     */
    public function gateways(): JsonResponse
    {
        $gateways = $this->gateways->enabled()
            ->map(fn ($g) => ['key' => $g->key, 'name' => $g->name])
            ->values()
            ->all();

        return ApiResponse::success($gateways);
    }

    /**
     * Suscripción actual + entitlements + uso.
     */
    public function subscription(Request $request): JsonResponse
    {
        $organization = $this->context->organization();
        $this->authorizeBilling();

        $subscription = $this->subscriptions->find($organization);
        $values = $this->entitlements->forOrganization($organization);

        return ApiResponse::success([
            'subscription' => $subscription ? [
                'id' => $subscription->public_id,
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
                'interval' => $subscription->interval,
                'plan' => $subscription->plan?->key,
                'plan_name' => $subscription->plan?->name,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'gateway' => $subscription->gateway,
            ] : null,
            'entitlements' => $values,
            'usage' => [
                Entitlement::BRANDS_MAX => Brand::query()->count(),
                Entitlement::TEAM_MEMBERS_MAX => $organization->users()->count(),
            ],
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $organization = $this->context->organization();
        $this->authorizeChangePlan();

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'key')->where('is_active', true)],
            'interval' => ['required', Rule::in(['month', 'year'])],
            'gateway' => ['required', 'string'],
        ]);

        $gatewayRecord = $this->gateways->record($data['gateway']);
        if ($gatewayRecord === null || ! $gatewayRecord->is_enabled) {
            throw ValidationException::withMessages([
                'gateway' => 'La pasarela de pago seleccionada no está disponible.',
            ]);
        }

        $plan = Plan::query()->where('key', $data['plan'])->firstOrFail();
        $adapter = $this->gateways->adapter($data['gateway']);
        $credentials = $gatewayRecord->credentialMap($gatewayRecord->environment);

        try {
            $result = $adapter->startSubscription($organization, $plan, $data['interval'], $credentials);
        } catch (GatewayNotImplementedException $e) {
            return ApiResponse::error($e->getMessage(), 'gateway_not_implemented', status: 422);
        }

        if ($result->activated) {
            $this->subscriptions->activatePlan(
                $organization,
                $plan,
                $data['interval'],
                $data['gateway'],
                $result->gatewaySubscriptionId,
            );

            return ApiResponse::message('Suscripción actualizada al plan ' . $plan->name . '.');
        }

        return ApiResponse::success(
            ['redirect_url' => $result->redirectUrl],
            'Continúa el pago en la pasarela.',
        );
    }

    public function cancel(Request $request): JsonResponse
    {
        $organization = $this->context->organization();
        $this->authorizeCancel();

        $subscription = $this->subscriptions->cancel($organization, atPeriodEnd: true);
        if ($subscription === null) {
            return ApiResponse::error('No hay una suscripción activa.', 'no_subscription', status: 409);
        }

        return ApiResponse::message('Tu suscripción se cancelará al final del periodo.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPlan(Plan $plan): array
    {
        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'trial_days' => $plan->trial_days,
            'prices' => $plan->prices->map(fn ($p) => [
                'interval' => $p->interval,
                'currency' => $p->currency,
                'amount_cents' => $p->amount_cents,
            ])->values()->all(),
            'entitlements' => $plan->entitlements->mapWithKeys(fn ($e) => [
                $e->entitlement_key => Entitlement::isBool($e->entitlement_key)
                    ? ($e->value === '1')
                    : (int) $e->value,
            ])->all(),
        ];
    }

    private function authorizeBilling(): void
    {
        abort_unless($this->userCan('billing.view'), 403);
    }

    private function authorizeChangePlan(): void
    {
        abort_unless($this->userCan('billing.change_plan'), 403);
    }

    private function authorizeCancel(): void
    {
        abort_unless($this->userCan('billing.cancel_subscription'), 403);
    }

    private function userCan(string $permission): bool
    {
        return request()->user()?->can($permission) ?? false;
    }
}
