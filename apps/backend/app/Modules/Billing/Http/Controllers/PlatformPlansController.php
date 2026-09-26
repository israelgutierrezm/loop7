<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\AddOn;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanEntitlement;
use App\Modules\Billing\Models\PlanPrice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\EntitlementsService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Planes, precios, límites/funciones y add-ons gestionados por SUPERADMIN
 * (docs/08-09). Los planes son dominio propio: no dependen de la pasarela.
 */
class PlatformPlansController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    /**
     * Catálogo de límites y funciones que puede tener un plan.
     */
    public function entitlements(): JsonResponse
    {
        $items = [];
        foreach (Entitlement::definitions() as $key => $def) {
            $items[] = ['key' => $key, 'type' => $def['type'], 'label' => $def['label']];
        }

        return ApiResponse::success($items);
    }

    public function index(): JsonResponse
    {
        $counts = Subscription::query()->withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::TRIALING->value, SubscriptionStatus::ACTIVE->value, SubscriptionStatus::GRACE->value])
            ->selectRaw('plan_id, count(*) as total')
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id');

        $plans = Plan::query()->with(['prices', 'entitlements'])->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Plan $p) => $this->present($p, (int) ($counts[$p->id] ?? 0)))
            ->all();

        return ApiResponse::success($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request, null);

        $plan = DB::transaction(function () use ($data): Plan {
            $plan = Plan::query()->create([
                'key' => $data['key'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_public' => $data['is_public'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
                'trial_days' => $data['trial_days'] ?? 0,
            ]);
            $this->syncPrices($plan, $data['prices'] ?? []);
            $this->syncEntitlements($plan, $data['entitlements'] ?? []);

            return $plan;
        });

        $this->audit->log(AuditAction::PLAN_SAVED, $plan, ['plan' => $plan->key, 'created' => true]);

        return ApiResponse::success($this->present($plan->load(['prices', 'entitlements'])), 'Plan creado.', status: 201);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $model = Plan::query()->where('key', $plan)->firstOrFail();
        $data = $this->validatePlan($request, $model);

        DB::transaction(function () use ($model, $data): void {
            $model->fill(array_intersect_key($data, array_flip([
                'name', 'description', 'is_active', 'is_public', 'sort_order', 'trial_days',
            ])))->save();
            if (array_key_exists('prices', $data)) {
                $this->syncPrices($model, $data['prices']);
            }
            if (array_key_exists('entitlements', $data)) {
                $this->syncEntitlements($model, $data['entitlements']);
            }
        });

        $this->entitlements->flush();
        $this->audit->log(AuditAction::PLAN_SAVED, $model, ['plan' => $model->key, 'changes' => array_keys($data)]);

        return ApiResponse::success($this->present($model->load(['prices', 'entitlements'])), 'Plan actualizado.');
    }

    public function destroy(string $plan): JsonResponse
    {
        $model = Plan::query()->where('key', $plan)->firstOrFail();

        if (Subscription::query()->withoutGlobalScopes()->where('plan_id', $model->id)->exists()) {
            return ApiResponse::error(
                'El plan tiene suscripciones asociadas: desactívalo para que no se pueda contratar.',
                'plan_in_use',
                status: 409,
            );
        }

        $model->delete();
        $this->audit->log(AuditAction::PLAN_DELETED, null, ['plan' => $plan]);

        return ApiResponse::message('Plan eliminado.');
    }

    public function addOns(): JsonResponse
    {
        return ApiResponse::success(AddOn::query()->orderBy('name')->get()->map(fn (AddOn $a) => $this->presentAddOn($a))->all());
    }

    public function storeAddOn(Request $request): JsonResponse
    {
        $data = $this->validateAddOn($request, null);
        $addOn = AddOn::query()->create([...$data, 'currency' => mb_strtoupper($data['currency'] ?? 'USD')]);
        $this->audit->log(AuditAction::PLAN_SAVED, $addOn, ['add_on' => $addOn->key, 'created' => true]);

        return ApiResponse::success($this->presentAddOn($addOn), 'Add-on creado.', status: 201);
    }

    public function updateAddOn(Request $request, string $addOn): JsonResponse
    {
        $model = AddOn::query()->where('key', $addOn)->firstOrFail();
        $data = $this->validateAddOn($request, $model);
        if (isset($data['currency'])) {
            $data['currency'] = mb_strtoupper($data['currency']);
        }
        $model->fill($data)->save();

        $this->entitlements->flush();
        $this->audit->log(AuditAction::PLAN_SAVED, $model, ['add_on' => $model->key]);

        return ApiResponse::success($this->presentAddOn($model), 'Add-on actualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request, ?Plan $plan): array
    {
        $creating = $plan === null;

        $data = $request->validate([
            'key' => $creating ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans', 'key')] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'prices' => ['sometimes', 'array', 'max:20'],
            'prices.*.interval' => ['required', Rule::enum(BillingInterval::class)],
            'prices.*.currency' => ['required', 'string', 'size:3', 'alpha'],
            'prices.*.amount_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'entitlements' => ['sometimes', 'array'],
        ], [
            'key.regex' => 'La clave sólo admite minúsculas, números y guiones.',
        ]);

        $seen = [];
        foreach ($data['prices'] ?? [] as $price) {
            $combo = $price['interval'] . '-' . mb_strtoupper($price['currency']);
            if (isset($seen[$combo])) {
                throw ValidationException::withMessages(['prices' => "Precio repetido para {$combo}."]);
            }
            $seen[$combo] = true;
        }

        foreach ($data['entitlements'] ?? [] as $key => $value) {
            if (! in_array($key, Entitlement::all(), true)) {
                throw ValidationException::withMessages(['entitlements' => "Límite desconocido: {$key}."]);
            }
            $valid = Entitlement::isBool($key)
                ? is_bool($value)
                : (is_int($value) && $value >= Entitlement::UNLIMITED);
            if (! $valid) {
                throw ValidationException::withMessages([
                    'entitlements' => "Valor no válido para {$key} (usa un entero, -1 = ilimitado, o sí/no).",
                ]);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAddOn(Request $request, ?AddOn $addOn): array
    {
        $limits = array_values(array_filter(Entitlement::all(), fn (string $k) => ! Entitlement::isBool($k)));
        $creating = $addOn === null;

        return $request->validate([
            'key' => $creating ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('add_ons', 'key')] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'entitlement_key' => [$creating ? 'required' : 'sometimes', Rule::in($limits)],
            'quantity_per_unit' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:1000000'],
            'price_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  list<array{interval: string, currency: string, amount_cents: int}>  $prices
     */
    private function syncPrices(Plan $plan, array $prices): void
    {
        $keep = [];
        foreach ($prices as $price) {
            $record = PlanPrice::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'interval' => $price['interval'], 'currency' => mb_strtoupper($price['currency'])],
                ['amount_cents' => (int) $price['amount_cents'], 'is_active' => true],
            );
            $keep[] = $record->id;
        }

        PlanPrice::query()->where('plan_id', $plan->id)->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  array<string, int|bool>  $entitlements
     */
    private function syncEntitlements(Plan $plan, array $entitlements): void
    {
        foreach ($entitlements as $key => $value) {
            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'entitlement_key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Plan $plan, ?int $subscribers = null): array
    {
        $values = [];
        foreach (Entitlement::all() as $key) {
            $values[$key] = Entitlement::isBool($key) ? false : 0;
        }
        foreach ($plan->entitlements as $e) {
            if (array_key_exists($e->entitlement_key, $values)) {
                $values[$e->entitlement_key] = Entitlement::isBool($e->entitlement_key) ? $e->value === '1' : (int) $e->value;
            }
        }

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'is_active' => $plan->is_active,
            'is_public' => $plan->is_public,
            'sort_order' => $plan->sort_order,
            'trial_days' => $plan->trial_days,
            'prices' => $plan->prices->map(fn (PlanPrice $p) => [
                'interval' => $p->interval,
                'currency' => $p->currency,
                'amount_cents' => $p->amount_cents,
            ])->values()->all(),
            'entitlements' => $values,
            'subscribers' => $subscribers ?? Subscription::query()->withoutGlobalScopes()->where('plan_id', $plan->id)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAddOn(AddOn $addOn): array
    {
        return [
            'key' => $addOn->key,
            'name' => $addOn->name,
            'description' => $addOn->getAttribute('description'),
            'entitlement_key' => $addOn->entitlement_key,
            'quantity_per_unit' => $addOn->quantity_per_unit,
            'price_cents' => $addOn->price_cents,
            'currency' => $addOn->currency,
            'is_active' => $addOn->is_active,
        ];
    }
}
