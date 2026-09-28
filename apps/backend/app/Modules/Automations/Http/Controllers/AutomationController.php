<?php

declare(strict_types=1);

namespace App\Modules\Automations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Automations\Enums\AutomationActionType;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Enums\ConditionOperator;
use App\Modules\Automations\Enums\NotifyAudience;
use App\Modules\Automations\Exceptions\FeedException;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Models\AutomationRun;
use App\Modules\Automations\Services\AutomationService;
use App\Modules\Automations\Services\FeedReader;
use App\Modules\Automations\Services\RssFeedPoller;
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
 * feature de plan feature.automations. Todo acotado a la Organization. La
 * persistencia y la auditoría viven en AutomationService.
 */
class AutomationController extends Controller
{
    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly TenantContext $tenant,
        private readonly AutomationService $automations,
    ) {
    }

    public function meta(Request $request): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.view');

        return ApiResponse::success([
            'triggers' => array_map(fn (AutomationTrigger $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'description' => $t->description(),
                'fields' => $t->fields(),
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
            ], ConditionOperator::cases()),
            'audiences' => array_map(fn (NotifyAudience $a) => [
                'value' => $a->value,
                'label' => $a->label(),
            ], NotifyAudience::cases()),
            'feed_poll_minutes' => RssFeedPoller::POLL_MINUTES,
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

        $automation = $this->automations->create($this->validated($request), $request->user());

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

        $this->automations->update($model, $this->validated($request));

        return ApiResponse::success($this->present($model->load('brand:id,public_id,name')), 'Automatización actualizada.');
    }

    public function destroy(Request $request, string $automation): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.delete');
        $this->automations->delete($this->resolve($automation));

        return ApiResponse::message('Automatización eliminada.');
    }

    /**
     * Nueva URL del webhook entrante (la anterior deja de funcionar).
     */
    public function rotateInboundUrl(Request $request, string $automation): JsonResponse
    {
        $this->ensureEnabled($request, 'automations.update');
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
        $this->ensureEnabled($request, 'automations.view');
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
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_enabled' => ['boolean'],
            'trigger' => ['required', Rule::in(AutomationTrigger::values())],
            'trigger_config' => ['nullable', 'array'],
            'trigger_config.feed_url' => ['nullable', 'string', 'max:2048'],
            'brand' => ['nullable', 'string'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required_with:conditions', 'string', 'max:60'],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(ConditionOperator::values())],
            'conditions.*.value' => ['nullable', 'string', 'max:255'],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(AutomationActionType::values())],
            'actions.*.config' => ['nullable', 'array'],
        ]);

        $trigger = AutomationTrigger::from($data['trigger']);
        $this->validateActionConfigs($data['actions'], $trigger, ! empty($data['brand']), $request);
        $triggerConfig = $this->validateTriggerConfig($trigger, $data['trigger_config'] ?? []);

        $brandId = null;
        if (! empty($data['brand'])) {
            $brand = Brand::query()->where('public_id', $data['brand'])->firstOrFail();
            $this->authorize('view', $brand);
            $brandId = $brand->id;
        }

        return [
            'name' => $data['name'],
            'is_enabled' => $data['is_enabled'] ?? true,
            'trigger' => $trigger->value,
            'trigger_config' => $triggerConfig,
            'brand_id' => $brandId,
            'conditions' => array_values($data['conditions'] ?? []),
            'actions' => array_values($data['actions']),
        ];
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

    /**
     * Cada acción exige su propia configuración (se valida al guardar para no
     * descubrir el error cuando la regla ya se está ejecutando).
     *
     * @param  array<int, array{type: string, config?: array<string, mixed>|null}>  $actions
     */
    private function validateActionConfigs(array $actions, AutomationTrigger $trigger, bool $hasBrand, Request $request): void
    {
        $errors = [];

        foreach ($actions as $i => $action) {
            $config = $action['config'] ?? [];
            $text = fn (string $key): string => trim((string) ($config[$key] ?? ''));
            $type = AutomationActionType::from($action['type']);

            if (! $type->allowsTrigger($trigger)) {
                $errors["actions.{$i}.type"] = "«{$type->label()}» sólo funciona con mensajes del inbox.";

                continue;
            }

            switch ($type) {
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
                case AutomationActionType::CREATE_DRAFT:
                    // Crear contenido exige poder crearlo (sin escaladas vía automatización).
                    if (! $request->user()->can(Permission::CONTENT_CREATE)) {
                        $errors["actions.{$i}.type"] = 'No tienes permiso para crear contenido.';
                        break;
                    }
                    if (! $hasBrand) {
                        $errors["actions.{$i}.type"] = 'Elige la marca donde se crearán los borradores.';
                    }
                    if ($text('title') === '' || mb_strlen($text('title')) > 255) {
                        $errors["actions.{$i}.config.title"] = 'Escribe el título del borrador (máx. 255 caracteres).';
                    }
                    if (mb_strlen($text('body')) > 5000) {
                        $errors["actions.{$i}.config.body"] = 'El texto del borrador admite hasta 5000 caracteres.';
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
        $state = $a->state ?? [];

        return [
            'id' => $a->public_id,
            'name' => $a->name,
            'is_enabled' => $a->is_enabled,
            'trigger' => $a->trigger->value,
            'trigger_label' => $a->trigger->label(),
            'trigger_config' => $a->trigger_config ?? (object) [],
            // La URL lleva el token: sólo para quien puede editar la regla.
            'inbound_url' => request()->user()?->can('automations.update') ? $a->inboundUrl() : null,
            'feed' => $a->trigger === AutomationTrigger::RSS_ITEM_PUBLISHED ? [
                'title' => $state['feed_title'] ?? null,
                'last_polled_at' => $state['last_polled_at'] ?? null,
                'last_error' => $state['last_error'] ?? null,
                'ready' => is_array($state['seen'] ?? null),
            ] : null,
            'brand' => $a->relationLoaded('brand') ? $a->brand?->public_id : null,
            'brand_name' => $a->relationLoaded('brand') ? $a->brand?->name : null,
            'conditions' => $a->conditions ?? [],
            'actions' => $a->actions,
            'run_count' => $a->run_count,
            'last_run_at' => $a->last_run_at?->toIso8601String(),
        ];
    }
}
