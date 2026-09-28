<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookEvent;
use App\Modules\Webhooks\Http\Requests\SaveWebhookEndpointRequest;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookDeliverer;
use App\Modules\Webhooks\Services\WebhookEndpointService;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Webhooks salientes de la Organization actual (docs/11). Permiso api.manage
 * y plan con API (middleware EnsureWebhooksEnabled). Todo se resuelve por
 * public_id dentro de la Organization (anti-IDOR).
 */
class WebhookEndpointsController extends Controller
{
    public function __construct(
        private readonly WebhookEndpointService $endpoints,
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = WebhookEndpoint::query()->latest()->latest('id')->get()
            ->map(fn (WebhookEndpoint $e) => $this->present($e))
            ->all();

        return ApiResponse::success([
            'endpoints' => $items,
            'events' => array_map(fn (WebhookEvent $e) => ['value' => $e->value, 'label' => $e->label()], WebhookEvent::subscribable()),
            'limits' => [
                'max_endpoints' => WebhookEndpointService::MAX_PER_ORGANIZATION,
                'max_attempts' => WebhookDeliverer::MAX_ATTEMPTS,
                'disable_after_failures' => WebhookDeliverer::DISABLE_AFTER_FAILURES,
                'rotation_grace_hours' => WebhookEndpointService::ROTATION_GRACE_HOURS,
                'signature_tolerance_seconds' => WebhookSigner::TOLERANCE_SECONDS,
            ],
        ]);
    }

    public function store(SaveWebhookEndpointRequest $request): JsonResponse
    {
        /** @var array{url: string, description?: string|null, events: list<string>} $data */
        $data = $request->validated();
        $result = $this->endpoints->create($this->organization(), $data, $request->user());

        return ApiResponse::success([
            ...$this->present($result['endpoint']),
            'secret' => $result['secret'], // única vez
        ], 'Webhook creado. Copia el secreto de firma: no volverá a mostrarse.', status: 201);
    }

    public function update(SaveWebhookEndpointRequest $request, string $endpoint): JsonResponse
    {
        /** @var array{url?: string, description?: string|null, events?: list<string>, is_active?: bool} $data */
        $data = $request->validated();
        $model = $this->endpoints->update($this->resolve($endpoint), $data);

        return ApiResponse::success($this->present($model), 'Webhook actualizado.');
    }

    public function destroy(string $endpoint): JsonResponse
    {
        $this->endpoints->delete($this->resolve($endpoint));

        return ApiResponse::message('Webhook eliminado.');
    }

    public function rotateSecret(string $endpoint): JsonResponse
    {
        $model = $this->resolve($endpoint);
        $secret = $this->endpoints->rotateSecret($model);

        return ApiResponse::success([
            ...$this->present($model),
            'secret' => $secret, // única vez
        ], 'Secreto renovado. El anterior seguirá firmando ' . WebhookEndpointService::ROTATION_GRACE_HOURS . ' horas.');
    }

    public function test(Request $request, string $endpoint): JsonResponse
    {
        $delivery = $this->endpoints->sendTest($this->organization(), $this->resolve($endpoint), $request->user());

        return ApiResponse::success(
            $this->presentDelivery($delivery),
            $delivery->status === DeliveryStatus::SUCCEEDED ? 'El endpoint respondió correctamente.' : 'La prueba falló.',
        );
    }

    public function deliveries(Request $request, string $endpoint): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(DeliveryStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $model = $this->resolve($endpoint);

        $page = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $model->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20))
            ->through(fn (WebhookDelivery $d) => $this->presentDelivery($d));

        return ApiResponse::paginated($page);
    }

    public function redeliver(string $endpoint, string $delivery): JsonResponse
    {
        $model = $this->resolve($endpoint);
        $original = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $model->id)
            ->where('public_id', $delivery)
            ->firstOrFail();

        $copy = $this->endpoints->redeliver($original, $model);

        return ApiResponse::success($this->presentDelivery($copy), 'Mensaje reenviado a la cola.', status: 202);
    }

    private function resolve(string $publicId): WebhookEndpoint
    {
        return WebhookEndpoint::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function organization(): Organization
    {
        $organization = $this->tenant->organization();
        abort_if($organization === null, 403);

        return $organization;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebhookEndpoint $e): array
    {
        return [
            'id' => $e->public_id,
            'url' => $e->url,
            'description' => $e->description,
            'events' => $e->events,
            'is_active' => $e->is_active,
            'secret_hint' => $e->maskedSecret(),
            'rotating_until' => $e->previous_secret_expires_at?->isFuture() ? $e->previous_secret_expires_at->toIso8601String() : null,
            'consecutive_failures' => $e->consecutive_failures,
            'disabled_at' => $e->disabled_at?->toIso8601String(),
            'disabled_reason' => $e->disabled_reason,
            'last_delivery_at' => $e->last_delivery_at?->toIso8601String(),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDelivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->public_id,
            'event' => $d->event,
            'message_id' => $d->message_id,
            'status' => $d->status->value,
            'status_label' => $d->status->label(),
            'attempts' => $d->attempts,
            'response_status' => $d->response_status,
            'response_body' => $d->response_body,
            'error' => $d->error,
            'duration_ms' => $d->duration_ms,
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
            'payload' => $d->payload,
        ];
    }
}
