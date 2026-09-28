<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookEvent;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Str;

/**
 * Reparte un evento entre los endpoints activos de la Organization que se
 * suscribieron a él: una entrega por endpoint, todas con el mismo mensaje
 * (mismo webhook-id y cuerpo), y cada una en su propio job.
 */
final class WebhookDispatcher
{
    public function __construct(private readonly EntitlementsService $entitlements)
    {
    }

    /**
     * @param  array<string, mixed>  $data  datos del evento (sólo identificadores públicos)
     * @return int nº de entregas creadas
     */
    public function dispatch(int $organizationId, WebhookEvent $event, array $data): int
    {
        $endpoints = WebhookEndpoint::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event->value));
        if ($endpoints->isEmpty()) {
            return 0;
        }

        // Los webhooks forman parte de la API: si el plan ya no la incluye, no se envían.
        $organization = Organization::query()->find($organizationId);
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_API)) {
            return 0;
        }

        $messageId = self::newMessageId();
        $payload = self::payload($organization, $event, $data);

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->create([
                'organization_id' => $organizationId,
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event->value,
                'message_id' => $messageId,
                'payload' => $payload,
                'status' => DeliveryStatus::PENDING->value,
            ]);

            // Tras el commit: si el flujo que emitió el evento se deshace, no se envía nada.
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }

        return $endpoints->count();
    }

    /**
     * Cuerpo del mensaje (forma recomendada por Standard Webhooks).
     *
     * @param  array<string, mixed>  $data
     * @return array{type: string, timestamp: string, data: array<string, mixed>}
     */
    public static function payload(Organization $organization, WebhookEvent $event, array $data): array
    {
        return [
            'type' => $event->value,
            'timestamp' => now()->toIso8601String(),
            'data' => ['organization' => ['id' => $organization->public_id, 'name' => $organization->name]] + $data,
        ];
    }

    public static function newMessageId(): string
    {
        return 'msg_' . Str::ulid();
    }
}
