<?php

declare(strict_types=1);

namespace App\Modules\Automations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Automations\Enums\AutomationActionType;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Enums\ConditionMatch;
use App\Modules\Automations\Enums\ConditionOperator;
use App\Modules\Automations\Enums\NotifyAudience;
use App\Modules\Automations\Enums\WaitUnit;
use App\Modules\Automations\Exceptions\FeedException;
use App\Modules\Automations\Flow\AutomationFlow;
use App\Modules\Automations\Flow\FlowValidator;
use App\Modules\Automations\Http\Requests\SaveAutomationRequest;
use App\Modules\Automations\Http\Requests\SimulateAutomationRequest;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Models\AutomationRun;
use App\Modules\Automations\Services\AutomationGate;
use App\Modules\Automations\Services\AutomationService;
use App\Modules\Automations\Services\FeedReader;
use App\Modules\Automations\Services\FlowSimulator;
use App\Modules\Automations\Services\RssFeedPoller;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandAccess;
use App\Support\Http\ApiResponse;
use App\Support\Security\OutboundUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Automatizaciones (docs/05): un disparador y un flujo de pasos que se edita en
 * el editor visual. Requiere permisos automations.* y el feature de plan
 * feature.automations. Todo acotado a la Organization (y a las marcas a las que
 * se tiene acceso). La persistencia y la auditoría viven en AutomationService.
 */
class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomationGate $gate,
        private readonly AutomationService $automations,
        private readonly FlowValidator $flows,
    ) {
    }

    public function meta(Request $request): JsonResponse
    {
        $this->gate->ensure($request->user(), Permission::AUTOMATIONS_VIEW);

        return ApiResponse::success([
            'triggers' => array_map(fn (AutomationTrigger $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'description' => $t->description(),
                'fields' => $t->fields(),
                'examples' => (object) $t->examples(),
                'external' => $t->isExternal(),
            ], AutomationTrigger::cases()),
            'actions' => array_map(fn (AutomationActionType $a) => [
                'value' => $a->value,
                'label' => $a->label(),
                'triggers' => $a->triggers() !== null ? array_map(fn (AutomationTrigger $t) => $t->value, $a->triggers()) : null,
            ], AutomationActionType::cases()),
            'operators' => array_map(fn (ConditionOperator $o) => [
                'value' => $o->value,
                'label' => $o->label(),
                'needs_value' => $o->needsValue(),
            ], ConditionOperator::cases()),
            'matches' => array_map(fn (ConditionMatch $m) => ['value' => $m->value, 'label' => $m->label()], ConditionMatch::cases()),
            'wait_units' => array_map(fn (WaitUnit $u) => ['value' => $u->value, 'label' => $u->label()], WaitUnit::cases()),
            'audiences' => array_map(fn (NotifyAudience $a) => [
                'value' => $a->value,
                'label' => $a->label(),
            ], NotifyAudience::cases()),
            'limits' => [
                'max_steps' => AutomationFlow::MAX_STEPS,
                'max_actions' => AutomationFlow::MAX_ACTIONS,
                'max_waits' => AutomationFlow::MAX_WAITS,
                'max_wait_days' => AutomationFlow::MAX_WAIT_DAYS,
                'max_depth' => AutomationFlow::MAX_DEPTH,
                'max_conditions' => AutomationFlow::MAX_CONDITIONS,
            ],
            'feed_poll_minutes' => RssFeedPoller::POLL_MINUTES,
        ]);
    }

    public function index(Request $request, BrandAccess $access): JsonResponse
    {
        $this->gate->ensure($request->user(), Permission::AUTOMATIONS_VIEW);

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

    public function store(SaveAutomationRequest $request): JsonResponse
    {
        $automation = $this->automations->create($this->attributes($request), $request->user());

        return ApiResponse::success($this->present($automation->load('brand:id,public_id,name')), 'Automatización creada.', status: 201);
    }

    public function show(Request $request, string $automation): JsonResponse
    {
        $this->gate->ensure($request->user(), Permission::AUTOMATIONS_VIEW);
        $model = $this->resolve($automation);

        $runs = $model->runs()->latest()->latest('id')->limit(20)->get();

        return ApiResponse::success([
            ...$this->present($model->load('brand:id,public_id,name')),
            'runs' => $runs->map(fn (AutomationRun $r) => [
                'id' => $r->public_id,
                'status' => $r->status->value,
                'message' => $r->message,
                'created_at' => $r->created_at?->toIso8601String(),
                'resume_at' => $r->resume_at?->toIso8601String(),
                'steps' => $r->steps ?? [],
            ])->all(),
            // Variables conocidas: las del disparador y las que trajo la última ejecución
            // (en un webhook entrante, los campos del JSON recibido).
            'fields' => array_values(array_diff(array_unique([
                ...$model->trigger->fields(),
                ...array_keys($runs->first()->context ?? []),
            ]), ['trigger'])),
        ]);
    }

    public function update(SaveAutomationRequest $request, string $automation): JsonResponse
    {
        $model = $this->resolve($automation);

        $this->automations->update($model, $this->attributes($request));

        return ApiResponse::success($this->present($model->load('brand:id,public_id,name')), 'Automatización actualizada.');
    }

    public function destroy(Request $request, string $automation): JsonResponse
    {
        $this->gate->ensure($request->user(), Permission::AUTOMATIONS_DELETE);
        $this->automations->delete($this->resolve($automation));

        return ApiResponse::message('Automatización eliminada.');
    }

    /**
     * Nueva URL del webhook entrante (la anterior deja de funcionar).
     */
    public function rotateInboundUrl(Request $request, string $automation): JsonResponse
    {
        $this->gate->ensure($request->user(), Permission::AUTOMATIONS_UPDATE);
        $model = $this->resolve($automation);
        abort_unless($model->trigger === AutomationTrigger::WEBHOOK_RECEIVED, 422, 'La automatización no usa un webhook entrante.');

        $this->automations->rotateInboundToken($model);

        return ApiResponse::success($this->present($model->load('brand:id,public_id,name')), 'URL renovada: la anterior ya no funciona.');
    }

    /**
     * Vista previa de un feed antes de guardar la regla (título y últimas entradas).
     */
    public function feedPreview(Request $request, FeedReader $reader): JsonResponse
    {
        $this->gate->ensure($request->user(), Permission::AUTOMATIONS_VIEW);
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        try {
            $feed = $reader->fetch($data['url']);
        } catch (FeedException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return ApiResponse::success([
            'title' => $feed->title,
            'items' => array_slice($feed->items, 0, 5),
        ]);
    }

    /**
     * «Probar»: por qué camino iría el flujo con unos datos de ejemplo y qué haría
     * cada acción, sin ejecutar nada.
     */
    public function simulate(SimulateAutomationRequest $request, FlowSimulator $simulator): JsonResponse
    {
        $brand = $request->validated('brand');
        $flow = $this->flows->validate($request->input('flow'), $request->trigger(), is_string($brand) && $brand !== '', $request->user());
        $this->brandId($brand); // sólo con una marca a la que se tiene acceso

        return ApiResponse::success($simulator->simulate($flow, $request->sampleContext()));
    }

    /**
     * Datos validados para guardar. Los errores del feed y los del flujo se
     * devuelven juntos.
     *
     * @return array<string, mixed>
     */
    private function attributes(SaveAutomationRequest $request): array
    {
        $trigger = $request->trigger();
        $brand = $request->validated('brand');
        $hasBrand = is_string($brand) && $brand !== '';

        $errors = [];
        $triggerConfig = null;
        $flow = null;
        try {
            $triggerConfig = $this->validateTriggerConfig($trigger, (array) ($request->validated('trigger_config') ?? []));
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        try {
            $flow = $this->flows->validate($request->input('flow'), $trigger, $hasBrand, $request->user());
        } catch (ValidationException $e) {
            $errors = [...$errors, ...$e->errors()];
        }
        if ($errors !== [] || $flow === null) {
            throw ValidationException::withMessages($errors);
        }
        $brandId = $this->brandId($brand);

        return [
            'name' => (string) $request->validated('name'),
            'is_enabled' => (bool) ($request->validated('is_enabled') ?? true),
            'trigger' => $trigger->value,
            'trigger_config' => $triggerConfig,
            'brand_id' => $brandId,
            'flow' => $flow->toArray(),
        ];
    }

    /**
     * Marca de la regla: sólo una a la que se tiene acceso.
     */
    private function brandId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $brand = Brand::query()->where('public_id', $publicId)->firstOrFail();
        $this->authorize('view', $brand);

        return $brand->id;
    }

    /**
     * El feed RSS debe ser una URL pública (anti-SSRF); se comprueba al guardar
     * y en cada lectura.
     *
     * @param  array<string, mixed>  $config
     * @return array{feed_url: string}|null
     */
    private function validateTriggerConfig(AutomationTrigger $trigger, array $config): ?array
    {
        if ($trigger !== AutomationTrigger::RSS_ITEM_PUBLISHED) {
            return null;
        }

        $url = trim((string) ($config['feed_url'] ?? ''));
        try {
            OutboundUrl::resolve($url);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['trigger_config.feed_url' => $url === '' ? 'Indica la URL del feed RSS.' : $e->getMessage()]);
        }

        return ['feed_url' => $url];
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

    /**
     * @return array<string, mixed>
     */
    private function present(Automation $a): array
    {
        $state = $a->state ?? [];
        $flow = $a->definition();

        return [
            'id' => $a->public_id,
            'name' => $a->name,
            'is_enabled' => $a->is_enabled,
            'trigger' => $a->trigger->value,
            'trigger_label' => $a->trigger->label(),
            'trigger_config' => $a->trigger_config ?? (object) [],
            // La URL lleva el token: sólo para quien puede editar la regla.
            'inbound_url' => request()->user()?->can(Permission::AUTOMATIONS_UPDATE) ? $a->inboundUrl() : null,
            'feed' => $a->trigger === AutomationTrigger::RSS_ITEM_PUBLISHED ? [
                'title' => $state['feed_title'] ?? null,
                'last_polled_at' => $state['last_polled_at'] ?? null,
                'last_error' => $state['last_error'] ?? null,
                'ready' => is_array($state['seen'] ?? null),
            ] : null,
            'brand' => $a->relationLoaded('brand') ? $a->brand?->public_id : null,
            'brand_name' => $a->relationLoaded('brand') ? $a->brand?->name : null,
            'flow' => $flow->toArray(),
            'steps_count' => count($flow->all()),
            'actions_count' => count($flow->actions()),
            'run_count' => $a->run_count,
            'last_run_at' => $a->last_run_at?->toIso8601String(),
        ];
    }
}
