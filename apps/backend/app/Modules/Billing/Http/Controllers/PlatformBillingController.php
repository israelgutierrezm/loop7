<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Models\AddOn;
use App\Modules\Billing\Models\OrganizationAddOn;
use App\Modules\Billing\Models\OrganizationEntitlementOverride;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Billing\Services\PaymentProcessor;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Jobs\ProcessPaymentWebhook;
use App\Modules\Payments\Models\Invoice;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operación del billing desde SUPERADMIN (docs/09): suscripciones, facturas,
 * transacciones, webhooks y ajustes comerciales por organización (plan de
 * cortesía, ampliar trial, excepciones de límites y add-ons).
 */
class PlatformBillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly EntitlementsService $entitlements,
        private readonly UsageService $usage,
        private readonly AuditLogger $audit,
    ) {
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $query = Subscription::query()->withoutGlobalScopes()
            ->with('plan:id,key,name')
            ->join('organizations', 'organizations.id', '=', 'subscriptions.organization_id')
            ->select('subscriptions.*', 'organizations.name as organization_name', 'organizations.public_id as organization_public_id')
            ->orderByDesc('subscriptions.updated_at');

        if ($request->filled('status')) {
            $query->where('subscriptions.status', $request->string('status')->toString());
        }
        if ($request->filled('plan')) {
            $query->whereHas('plan', fn ($q) => $q->where('key', $request->string('plan')->toString()));
        }
        if ($request->filled('search')) {
            $query->where('organizations.name', 'like', '%' . $request->string('search')->toString() . '%');
        }

        $page = $query->paginate(min(100, max(10, (int) $request->integer('per_page', 25))))
            ->through(fn (Subscription $s) => [
                'id' => $s->public_id,
                'organization' => ['id' => $s->getAttribute('organization_public_id'), 'name' => $s->getAttribute('organization_name')],
                'plan' => $s->plan?->key,
                'plan_name' => $s->plan?->name,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'interval' => $s->interval,
                'gateway' => $s->gateway,
                'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
                'current_period_end' => $s->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $s->cancel_at_period_end,
            ]);

        return ApiResponse::paginated($page);
    }

    /**
     * Ficha de billing de una organización (para la gestión comercial).
     */
    public function organization(string $organization): JsonResponse
    {
        $org = $this->resolveOrganization($organization);
        $subscription = $this->subscriptions->find($org);

        return ApiResponse::success([
            'organization' => ['id' => $org->public_id, 'name' => $org->name],
            'subscription' => $subscription === null ? null : [
                'plan' => $subscription->plan?->key,
                'plan_name' => $subscription->plan?->name,
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
                'interval' => $subscription->interval,
                'gateway' => $subscription->gateway,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
            ],
            'entitlements' => $this->entitlements->forOrganization($org),
            'usage' => $this->usage->current($org),
            'overrides' => OrganizationEntitlementOverride::query()->where('organization_id', $org->id)->get()
                ->map(fn (OrganizationEntitlementOverride $o) => [
                    'key' => $o->entitlement_key,
                    'value' => Entitlement::isBool($o->entitlement_key) ? $o->value === '1' : (int) $o->value,
                    'note' => $o->note,
                ])->values()->all(),
            'add_ons' => OrganizationAddOn::query()->withoutGlobalScopes()->with('addOn')->where('organization_id', $org->id)->get()
                ->map(fn (OrganizationAddOn $a) => ['key' => $a->addOn->key, 'name' => $a->addOn->name, 'quantity' => $a->quantity])
                ->values()->all(),
            'invoices' => Invoice::query()->withoutGlobalScopes()->where('organization_id', $org->id)->orderByDesc('id')->limit(20)->get()
                ->map(fn (Invoice $i) => $this->presentInvoice($i))->values()->all(),
        ]);
    }

    /**
     * Activa un plan sin cobro (cortesía, acuerdos comerciales, pagos fuera de línea).
     */
    public function changePlan(Request $request, string $organization): JsonResponse
    {
        $org = $this->resolveOrganization($organization);
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'key')],
            'interval' => ['required', Rule::in(['month', 'year'])],
        ]);

        $plan = Plan::query()->where('key', $data['plan'])->firstOrFail();
        $this->subscriptions->activatePlan($org, $plan, $data['interval'], 'manual');

        return ApiResponse::message("Plan {$plan->name} activado para {$org->name}.");
    }

    public function extendTrial(Request $request, string $organization): JsonResponse
    {
        $org = $this->resolveOrganization($organization);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);

        $subscription = $this->subscriptions->find($org) ?? $this->subscriptions->startTrial($org);
        if ($subscription === null) {
            return ApiResponse::error('No hay planes activos para iniciar un trial.', 'no_plans', status: 409);
        }
        $this->subscriptions->extendTrial($subscription, (int) $data['days']);

        return ApiResponse::message("Trial ampliado {$data['days']} días.");
    }

    public function cancel(Request $request, string $organization): JsonResponse
    {
        $org = $this->resolveOrganization($organization);
        $data = $request->validate(['immediately' => ['sometimes', 'boolean']]);

        $subscription = $this->subscriptions->cancel($org, atPeriodEnd: ! ($data['immediately'] ?? false));
        if ($subscription === null) {
            return ApiResponse::error('La organización no tiene suscripción.', 'no_subscription', status: 409);
        }

        return ApiResponse::message(($data['immediately'] ?? false) ? 'Suscripción cancelada.' : 'Se cancelará al final del periodo.');
    }

    /**
     * Sustituye las excepciones de límites/funciones de la organización.
     */
    public function saveOverrides(Request $request, string $organization): JsonResponse
    {
        $org = $this->resolveOrganization($organization);
        $data = $request->validate([
            'overrides' => ['present', 'array', 'max:30'],
            'overrides.*.key' => ['required', Rule::in(Entitlement::all())],
            'overrides.*.value' => ['required'],
            'overrides.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        $keys = [];
        foreach ($data['overrides'] as $override) {
            $key = $override['key'];
            $value = $override['value'];
            $valid = Entitlement::isBool($key)
                ? is_bool($value)
                : (is_int($value) && $value >= Entitlement::UNLIMITED);
            if (! $valid) {
                throw ValidationException::withMessages(['overrides' => "Valor no válido para {$key}."]);
            }

            OrganizationEntitlementOverride::query()->updateOrCreate(
                ['organization_id' => $org->id, 'entitlement_key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'note' => $override['note'] ?? null],
            );
            $keys[] = $key;
        }
        OrganizationEntitlementOverride::query()->where('organization_id', $org->id)->whereNotIn('entitlement_key', $keys)->delete();

        $this->entitlements->flush();
        $this->audit->log(AuditAction::ENTITLEMENT_OVERRIDE_SAVED, $org, ['keys' => $keys], organizationId: $org->id);

        return ApiResponse::success($this->entitlements->forOrganization($org), 'Excepciones guardadas.');
    }

    /**
     * Fija las cantidades de add-ons de la organización (0 = quitar).
     */
    public function saveAddOns(Request $request, string $organization): JsonResponse
    {
        $org = $this->resolveOrganization($organization);
        $data = $request->validate([
            'add_ons' => ['present', 'array', 'max:30'],
            'add_ons.*.key' => ['required', Rule::exists('add_ons', 'key')],
            'add_ons.*.quantity' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        foreach ($data['add_ons'] as $item) {
            $addOn = AddOn::query()->where('key', $item['key'])->firstOrFail();
            if ((int) $item['quantity'] === 0) {
                OrganizationAddOn::query()->withoutGlobalScopes()
                    ->where('organization_id', $org->id)->where('add_on_id', $addOn->id)->delete();

                continue;
            }
            OrganizationAddOn::query()->withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'add_on_id' => $addOn->id],
                ['quantity' => (int) $item['quantity']],
            );
        }

        $this->entitlements->flush();
        $this->audit->log(AuditAction::ADD_ON_ASSIGNED, $org, ['add_ons' => $data['add_ons']], organizationId: $org->id);

        return ApiResponse::success($this->entitlements->forOrganization($org), 'Add-ons actualizados.');
    }

    public function invoices(Request $request): JsonResponse
    {
        $query = Invoice::query()->withoutGlobalScopes()
            ->join('organizations', 'organizations.id', '=', 'invoices.organization_id')
            ->select('invoices.*', 'organizations.name as organization_name', 'organizations.public_id as organization_public_id')
            ->orderByDesc('invoices.id');

        if ($request->filled('status')) {
            $query->where('invoices.status', $request->string('status')->toString());
        }
        if ($request->filled('gateway')) {
            $query->where('invoices.gateway', $request->string('gateway')->toString());
        }

        $page = $query->paginate(min(100, max(10, (int) $request->integer('per_page', 25))))
            ->through(fn (Invoice $i) => $this->presentInvoice($i));

        return ApiResponse::paginated($page);
    }

    /**
     * Confirma el pago de una factura pendiente (transferencia recibida, etc.):
     * activa o renueva el plan de la factura y registra la transacción.
     */
    public function markPaid(Request $request, string $invoice, PaymentProcessor $processor): JsonResponse
    {
        $model = Invoice::query()->withoutGlobalScopes()->where('public_id', $invoice)->firstOrFail();
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        if ($model->status === Invoice::PAID) {
            return ApiResponse::error('La factura ya está pagada.', 'already_paid', status: 409);
        }

        $result = $processor->markInvoicePaid($model, $data['note'] ?? null);

        return ApiResponse::success($this->presentInvoice($model->fresh() ?? $model), 'Pago confirmado: ' . $result . '.');
    }

    public function voidInvoice(string $invoice): JsonResponse
    {
        $model = Invoice::query()->withoutGlobalScopes()->where('public_id', $invoice)->firstOrFail();
        if ($model->status !== Invoice::OPEN) {
            return ApiResponse::error('Sólo se pueden anular facturas pendientes.', 'not_open', status: 409);
        }

        $model->update(['status' => Invoice::VOID]);
        $this->audit->log(AuditAction::INVOICE_VOIDED, $model, ['number' => $model->number], organizationId: $model->organization_id);

        return ApiResponse::success($this->presentInvoice($model), 'Factura anulada.');
    }

    public function transactions(Request $request): JsonResponse
    {
        $page = PaymentTransaction::query()
            ->leftJoin('organizations', 'organizations.id', '=', 'payment_transactions.organization_id')
            ->select('payment_transactions.*', 'organizations.name as organization_name')
            ->when($request->filled('status'), fn ($q) => $q->where('payment_transactions.status', $request->string('status')->toString()))
            ->orderByDesc('payment_transactions.id')
            ->paginate(min(100, max(10, (int) $request->integer('per_page', 25))))
            ->through(fn (PaymentTransaction $t) => [
                'id' => $t->public_id,
                'organization' => $t->getAttribute('organization_name'),
                'gateway' => $t->gateway,
                'environment' => $t->environment,
                'provider_transaction_id' => $t->provider_transaction_id,
                'amount_cents' => $t->amount_cents,
                'currency' => $t->currency,
                'status' => $t->status,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated($page);
    }

    public function webhookEvents(Request $request): JsonResponse
    {
        $page = PaymentWebhookEvent::query()
            ->when($request->filled('gateway'), fn ($q) => $q->where('gateway', $request->string('gateway')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(min(100, max(10, (int) $request->integer('per_page', 25))))
            ->through(fn (PaymentWebhookEvent $e) => [
                'id' => $e->id,
                'gateway' => $e->gateway,
                'environment' => $e->environment,
                'provider_event_id' => $e->provider_event_id,
                'event_type' => $e->event_type,
                'status' => $e->status,
                'result' => $e->result,
                'error' => $e->error,
                // El código de verificación de Openpay se muestra para activar el webhook.
                'verification_code' => $e->event_type === 'verification' ? ($e->payload['verification_code'] ?? null) : null,
                'received_at' => $e->created_at?->toIso8601String(),
                'processed_at' => $e->processed_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated($page);
    }

    public function retryWebhook(string $event): JsonResponse
    {
        $model = PaymentWebhookEvent::query()->findOrFail((int) $event);
        if ($model->status !== 'failed') {
            return ApiResponse::error('Sólo se reintentan los eventos fallidos.', 'not_failed', status: 409);
        }

        $model->update(['status' => 'received', 'error' => null]);
        ProcessPaymentWebhook::dispatch($model->id);

        return ApiResponse::message('Reintento en marcha.');
    }

    private function resolveOrganization(string $publicId): Organization
    {
        return Organization::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->public_id,
            'number' => $invoice->number,
            'organization' => $invoice->getAttribute('organization_name')
                ?? Organization::query()->whereKey($invoice->organization_id)->value('name'),
            'description' => $invoice->description,
            'amount_cents' => $invoice->amount_cents,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'gateway' => $invoice->gateway,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }
}
