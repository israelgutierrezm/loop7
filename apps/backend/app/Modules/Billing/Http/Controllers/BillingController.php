<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Models\OrganizationAddOn;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\CheckoutService;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Payments\Models\Invoice;
use App\Modules\Payments\Services\GatewayManager;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Facturación del cliente: catálogo de planes, suscripción y uso, cambio de
 * plan (checkout en la pasarela), cancelación/reanudación e historial de facturas.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly SubscriptionService $subscriptions,
        private readonly UsageService $usage,
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
            ->with(['prices' => fn ($q) => $q->where('is_active', true), 'entitlements'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => $this->presentPlan($plan))
            ->all();

        return ApiResponse::success($plans);
    }

    /**
     * Pasarelas de pago habilitadas y la moneda en la que cobra cada una.
     */
    public function gateways(): JsonResponse
    {
        $gateways = $this->gateways->enabled()
            ->filter(fn ($g) => $this->gateways->adapter($g->key) !== null)
            ->map(fn ($g) => [
                'key' => $g->key,
                'name' => $g->name,
                'currency' => $this->gateways->currency($g),
                'is_offline' => $g->key === 'manual',
            ])
            ->values()
            ->all();

        return ApiResponse::success($gateways);
    }

    /**
     * Suscripción actual + límites del plan + uso + solicitud de pago pendiente.
     */
    public function subscription(): JsonResponse
    {
        $organization = $this->context->organization();
        $this->authorizePermission('billing.view');

        $subscription = $this->subscriptions->find($organization);
        $pending = Invoice::query()->where('status', Invoice::OPEN)->latest('id')->first();
        $addOns = OrganizationAddOn::query()->with('addOn')->where('organization_id', $organization->id)->get();

        return ApiResponse::success([
            'subscription' => $subscription ? [
                'id' => $subscription->public_id,
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
                'grants_access' => $subscription->grantsAccess(),
                'interval' => $subscription->interval,
                'plan' => $subscription->plan?->key,
                'plan_name' => $subscription->plan?->name,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'gateway' => $subscription->gateway,
                'auto_renews' => in_array($subscription->gateway, ['stripe', 'mercadopago'], true),
            ] : null,
            'entitlements' => $this->entitlements->forOrganization($organization),
            'usage' => $this->usage->current($organization),
            'pending_invoice' => $pending ? $this->presentInvoice($pending) : null,
            'add_ons' => $addOns->map(fn (OrganizationAddOn $a) => [
                'name' => $a->addOn->name,
                'quantity' => $a->quantity,
                'entitlement' => $a->addOn->entitlement_key,
                'adds' => $a->addOn->quantity_per_unit * $a->quantity,
            ])->all(),
        ]);
    }

    /**
     * Inicia el cambio de plan: devuelve la URL de pago de la pasarela o, en pago
     * manual, las instrucciones. El plan se activa al confirmarse el pago.
     */
    public function subscribe(Request $request, CheckoutService $checkout): JsonResponse
    {
        $organization = $this->context->organization();
        $this->authorizePermission('billing.change_plan');

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'key')->where('is_active', true)],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            'gateway' => ['required', 'string'],
        ]);

        $plan = Plan::query()->where('key', $data['plan'])->firstOrFail();
        $result = $checkout->start($organization, $plan, $data['interval'], $data['gateway'], $request->user());

        return ApiResponse::success([
            'status' => $result['status'],
            'redirect_url' => $result['redirect_url'],
            'message' => $result['message'],
            'invoice' => $this->presentInvoice($result['invoice']),
        ], $result['status'] === 'redirect'
            ? 'Continúa el pago en la pasarela.'
            : 'Solicitud registrada: el plan se activará al confirmar el pago.');
    }

    public function cancel(): JsonResponse
    {
        $organization = $this->context->organization();
        $this->authorizePermission('billing.cancel_subscription');

        $subscription = $this->subscriptions->cancel($organization, atPeriodEnd: true);
        if ($subscription === null) {
            return ApiResponse::error('No hay una suscripción activa.', 'no_subscription', status: 409);
        }

        $this->syncGateway($subscription->gateway, $subscription->gateway_subscription_id, cancel: true);

        return ApiResponse::message('Tu suscripción se cancelará al final del periodo.');
    }

    public function resume(): JsonResponse
    {
        $organization = $this->context->organization();
        $this->authorizePermission('billing.change_plan');

        $subscription = $this->subscriptions->find($organization);
        if ($subscription === null || ! $subscription->cancel_at_period_end || ! $subscription->grantsAccess()) {
            return ApiResponse::error('No hay una cancelación programada que reanudar.', 'nothing_to_resume', status: 409);
        }

        $this->subscriptions->resume($subscription);
        $this->syncGateway($subscription->gateway, $subscription->gateway_subscription_id, cancel: false);

        return ApiResponse::message('Tu suscripción seguirá activa.');
    }

    /**
     * Historial de facturas de la Organization (acotado por OrganizationScope).
     */
    public function invoices(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.view');

        $page = Invoice::query()
            ->where('status', '!=', Invoice::VOID)
            ->orderByDesc('id')
            ->paginate(min(50, max(5, (int) $request->integer('per_page', 20))))
            ->through(fn (Invoice $i) => $this->presentInvoice($i));

        return ApiResponse::paginated($page);
    }

    /**
     * Refleja la cancelación/reanudación en la pasarela (Stripe cancela al final
     * del periodo; el resto no tiene cobro automático que detener aún).
     */
    private function syncGateway(?string $gateway, ?string $gatewaySubscriptionId, bool $cancel): void
    {
        if ($gateway === null || $gatewaySubscriptionId === null || $gatewaySubscriptionId === '') {
            return;
        }

        $record = $this->gateways->record($gateway);
        $adapter = $this->gateways->adapter($gateway);
        if ($record === null || $adapter === null) {
            return;
        }

        try {
            $credentials = $this->gateways->credentials($record);
            $cancel
                ? $adapter->cancelSubscription($gatewaySubscriptionId, $credentials, atPeriodEnd: true)
                : $adapter->resumeSubscription($gatewaySubscriptionId, $credentials);
        } catch (Throwable $e) {
            Log::warning('No se pudo sincronizar la cancelación con la pasarela.', [
                'gateway' => $gateway, 'error' => $e->getMessage(),
            ]);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->public_id,
            'number' => $invoice->number,
            'description' => $invoice->description,
            'amount_cents' => $invoice->amount_cents,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'gateway' => $invoice->gateway,
            'checkout_url' => $invoice->isOpen() ? ($invoice->meta['checkout_url'] ?? null) : null,
            'instructions' => $invoice->isOpen() && $invoice->gateway === 'manual'
                ? ($this->gateways->record('manual')->config['instructions'] ?? null)
                : null,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless(request()->user()?->can($permission) ?? false, 403);
    }
}
