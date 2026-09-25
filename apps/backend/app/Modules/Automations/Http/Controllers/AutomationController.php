<?php

declare(strict_types=1);

namespace App\Modules\Automations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Automations\Enums\AutomationActionType;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Enums\ConditionOperator;
use App\Modules\Automations\Enums\NotifyAudience;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Models\AutomationRun;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandAccess;
use App\Support\Http\ApiResponse;
use App\Support\Security\OutboundUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CRUD de automatizaciones (docs/05). Requiere permisos automations.* y el
 * feature de plan feature.automations. Todo acotado a la Organization.
 */
class AutomationController extends Controller
{
    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly TenantContext $tenant,
    ) {
    }

    public function meta(Request $request): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.view');

        return ApiResponse::success([
            'triggers' => array_map(fn (AutomationTrigger $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'fields' => $t->fields(),
            ], AutomationTrigger::cases()),
            'actions' => array_map(fn (AutomationActionType $a) => [
                'value' => $a->value,
                'label' => $a->label(),
            ], AutomationActionType::cases()),
            'operators' => array_map(fn (ConditionOperator $o) => [
                'value' => $o->value,
                'label' => $o->label(),
            ], ConditionOperator::cases()),
            'audiences' => array_map(fn (NotifyAudience $a) => [
                'value' => $a->value,
                'label' => $a->label(),
            ], NotifyAudience::cases()),
        ]);
    }

    public function index(Request $request, BrandAccess $access): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.view');

        // Las de toda la Organization y las de las Brands a las que tiene acceso.
        $restricted = $access->restrictedBrandIds($request->user());

        $items = Automation::query()
            ->with('brand:id,public_id,name')
            ->when($restricted !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('brand_id')->orWhereIn('brand_id', $restricted ?? []),
            ))
            ->latest()
            ->latest('id')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 30))))
            ->through(fn (Automation $a) => $this->present($a));

        return ApiResponse::paginated($items);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.create');

        $data = $this->validated($request);
        $data['created_by_user_id'] = $request->user()->id;

        $automation = Automation::query()->create($data);

        return ApiResponse::success($this->present($automation->load('brand:id,public_id,name')), 'Automatización creada.', status: 201);
    }

    public function show(Request $request, string $automation): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.view');
        $model = $this->resolve($automation);

        $runs = $model->runs()->latest()->limit(20)->get()->map(fn (AutomationRun $r) => [
            'id' => $r->public_id,
            'status' => $r->status,
            'message' => $r->message,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->all();

        return ApiResponse::success([
            ...$this->present($model->load('brand:id,public_id,name')),
            'runs' => $runs,
        ]);
    }

    public function update(Request $request, string $automation): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.update');
        $model = $this->resolve($automation);

        $model->update($this->validated($request));

        return ApiResponse::success($this->present($model->load('brand:id,public_id,name')), 'Automatización actualizada.');
    }

    public function destroy(Request $request, string $automation): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.delete');
        $model = $this->resolve($automation);
        $model->delete();

        return ApiResponse::message('Automatización eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_enabled' => ['boolean'],
            'trigger' => ['required', Rule::in(AutomationTrigger::values())],
            'brand' => ['nullable', 'string'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required_with:conditions', 'string', 'max:60'],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(ConditionOperator::values())],
            'conditions.*.value' => ['nullable', 'string', 'max:255'],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(AutomationActionType::values())],
            'actions.*.config' => ['nullable', 'array'],
        ]);

        $this->validateActionConfigs($data['actions']);

        $brandId = null;
        if (! empty($data['brand'])) {
            $brand = Brand::query()->where('public_id', $data['brand'])->firstOrFail();
            $this->authorize('view', $brand);
            $brandId = $brand->id;
        }

        return [
            'name' => $data['name'],
            'is_enabled' => $data['is_enabled'] ?? true,
            'trigger' => $data['trigger'],
            'brand_id' => $brandId,
            'conditions' => array_values($data['conditions'] ?? []),
            'actions' => array_values($data['actions']),
        ];
    }

    /**
     * Cada acción exige su propia configuración (se valida al guardar para no
     * descubrir el error cuando la regla ya se está ejecutando).
     *
     * @param  array<int, array{type: string, config?: array<string, mixed>|null}>  $actions
     */
    private function validateActionConfigs(array $actions): void
    {
        $errors = [];

        foreach ($actions as $i => $action) {
            $config = $action['config'] ?? [];
            $text = fn (string $key): string => trim((string) ($config[$key] ?? ''));

            switch (AutomationActionType::from($action['type'])) {
                case AutomationActionType::NOTIFY:
                    if ($text('message') === '' || mb_strlen($text('message')) > 1000) {
                        $errors["actions.{$i}.config.message"] = 'Escribe el mensaje del aviso (máx. 1000 caracteres).';
                    }
                    if ($text('audience') !== '' && NotifyAudience::tryFrom($text('audience')) === null) {
                        $errors["actions.{$i}.config.audience"] = 'Elige a quién avisar.';
                    }
                    break;
                case AutomationActionType::WEBHOOK:
                    try {
                        OutboundUrl::resolve($text('url'));
                    } catch (InvalidArgumentException $e) {
                        $errors["actions.{$i}.config.url"] = $e->getMessage();
                    }
                    break;
                case AutomationActionType::INBOX_REPLY:
                    if ($text('message') === '' || mb_strlen($text('message')) > 2000) {
                        $errors["actions.{$i}.config.message"] = 'Escribe la respuesta automática (máx. 2000 caracteres).';
                    }
                    break;
                case AutomationActionType::INBOX_TAG:
                    if ($text('tag') === '' || mb_strlen($text('tag')) > 40) {
                        $errors["actions.{$i}.config.tag"] = 'Indica la etiqueta (máx. 40 caracteres).';
                    }
                    break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolve(string $publicId): Automation
    {
        $automation = Automation::query()->with('brand')->where('public_id', $publicId)->firstOrFail();

        // Una automatización de una Brand concreta sólo la gestiona quien tiene acceso a ella.
        if ($automation->brand_id !== null) {
            abort_if($automation->brand === null, 404);
            $this->authorize('view', $automation->brand);
        }

        return $automation;
    }

    private function ensureEnabled(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);

        $organization = $this->tenant->organization();
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_AUTOMATIONS)) {
            throw new PlanLimitExceededException('Tu plan no incluye automatizaciones.', Entitlement::FEATURE_AUTOMATIONS);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Automation $a): array
    {
        return [
            'id' => $a->public_id,
            'name' => $a->name,
            'is_enabled' => $a->is_enabled,
            'trigger' => $a->trigger->value,
            'trigger_label' => $a->trigger->label(),
            'brand' => $a->relationLoaded('brand') ? $a->brand?->public_id : null,
            'brand_name' => $a->relationLoaded('brand') ? $a->brand?->name : null,
            'conditions' => $a->conditions ?? [],
            'actions' => $a->actions,
            'run_count' => $a->run_count,
            'last_run_at' => $a->last_run_at?->toIso8601String(),
        ];
    }
}
