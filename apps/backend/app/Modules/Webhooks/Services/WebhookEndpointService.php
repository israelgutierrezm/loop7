<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookEvent;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Validation\ValidationException;

/**
 * Casos de uso de los endpoints de webhooks: alta (el secreto se muestra una
 * vez), edición, reactivación, rotación del secreto, prueba y reenvío. En la
 * auditoría sólo queda el dominio de la URL: la ruta puede ser una credencial
 * del receptor (p. ej. los «catch hooks» de Zapier).
 */
final class WebhookEndpointService
{
    public const MAX_PER_ORGANIZATION = 10;

    /** Horas que el secreto anterior sigue firmando tras rotarlo. */
    public const ROTATION_GRACE_HOURS = 24;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WebhookDeliverer $deliverer,
    ) {
    }

    /**
     * @param  array{url: string, description?: string|null, events: list<string>}  $data
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public function create(Organization $organization, array $data, User $user): array
    {
        if (WebhookEndpoint::query()->count() >= self::MAX_PER_ORGANIZATION) {
            throw ValidationException::withMessages([
                'url' => 'Tu organización ya tiene ' . self::MAX_PER_ORGANIZATION . ' webhooks: elimina uno que no uses.',
            ]);
        }

        $secret = WebhookSigner::generateSecret();
        $endpoint = WebhookEndpoint::query()->create([
            'organization_id' => $organization->id,
            'url' => $data['url'],
            'description' => $data['description'] ?? null,
            'events' => array_values(array_unique($data['events'])),
            'secret' => $secret,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        $this->audit->log(AuditAction::WEBHOOK_ENDPOINT_CREATED, $endpoint, [
            'host' => $this->host($endpoint->url),
            'events' => $endpoint->events,
        ]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /**
     * @param  array{url?: string, description?: string|null, events?: list<string>, is_active?: bool}  $data
     */
    public function update(WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        if (array_key_exists('url', $data)) {
            $endpoint->url = $data['url'];
        }
        if (array_key_exists('description', $data)) {
            $endpoint->description = $data['description'];
        }
        if (array_key_exists('events', $data)) {
            $endpoint->events = array_values(array_unique($data['events']));
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== $endpoint->is_active) {
            $endpoint->is_active = $data['is_active'];
            // Reactivar da una nueva oportunidad: se olvidan los fallos acumulados.
            $endpoint->forceFill($data['is_active']
                ? ['consecutive_failures' => 0, 'disabled_at' => null, 'disabled_reason' => null]
                : ['disabled_at' => now(), 'disabled_reason' => null]);
        }

        $changes = array_keys($endpoint->getDirty());
        $endpoint->save();

        if ($changes !== []) {
            $this->audit->log(AuditAction::WEBHOOK_ENDPOINT_UPDATED, $endpoint, [
                'host' => $this->host($endpoint->url),
                'changes' => array_values(array_intersect($changes, ['url', 'description', 'events', 'is_active'])),
                'is_active' => $endpoint->is_active,
            ]);
        }

        return $endpoint;
    }

    /**
     * Genera un secreto nuevo; el anterior sigue firmando unas horas para que
     * el receptor pueda actualizarlo sin perder mensajes.
     */
    public function rotateSecret(WebhookEndpoint $endpoint): string
    {
        $secret = WebhookSigner::generateSecret();
        $endpoint->forceFill([
            'previous_secret' => $endpoint->secret,
            'previous_secret_expires_at' => now()->addHours(self::ROTATION_GRACE_HOURS),
            'secret' => $secret,
        ])->save();

        $this->audit->log(AuditAction::WEBHOOK_SECRET_ROTATED, $endpoint, ['host' => $this->host($endpoint->url)]);

        return $secret;
    }

    public function delete(WebhookEndpoint $endpoint): void
    {
        $host = $this->host($endpoint->url);
        $endpoint->delete();

        $this->audit->log(AuditAction::WEBHOOK_ENDPOINT_DELETED, null, ['host' => $host]);
    }

    /**
     * Envía un mensaje `webhook.test` al momento (un solo intento, sin contar
     * como fallo del endpoint) y devuelve la entrega con su resultado.
     */
    public function sendTest(Organization $organization, WebhookEndpoint $endpoint, User $user): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'organization_id' => $organization->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => WebhookEvent::TEST->value,
            'message_id' => WebhookDispatcher::newMessageId(),
            'payload' => WebhookDispatcher::payload($organization, WebhookEvent::TEST, [
                'message' => 'Mensaje de prueba enviado desde Loop7.',
                'sent_by' => ['id' => $user->public_id, 'name' => $user->name],
            ]),
            'status' => DeliveryStatus::PENDING->value,
        ]);

        $ok = $this->deliverer->attempt($delivery, $endpoint);
        $delivery->forceFill([
            'status' => ($ok ? DeliveryStatus::SUCCEEDED : DeliveryStatus::FAILED)->value,
            'delivered_at' => $ok ? now() : null,
        ])->save();

        return $delivery;
    }

    /**
     * Reenvía un mensaje ya resuelto como una entrega nueva con el mismo
     * webhook-id y cuerpo (el receptor puede descartarlo si ya lo procesó).
     */
    public function redeliver(WebhookDelivery $delivery, WebhookEndpoint $endpoint): WebhookDelivery
    {
        if (! $endpoint->is_active) {
            throw ValidationException::withMessages(['endpoint' => 'Activa el webhook antes de reenviar mensajes.']);
        }
        if ($delivery->status === DeliveryStatus::PENDING) {
            throw ValidationException::withMessages(['delivery' => 'Esta entrega aún se está intentando.']);
        }

        $copy = WebhookDelivery::query()->create([
            'organization_id' => $delivery->organization_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $delivery->event,
            'message_id' => $delivery->message_id,
            'payload' => $delivery->payload,
            'status' => DeliveryStatus::PENDING->value,
        ]);
        DeliverWebhook::dispatch($copy->id);

        return $copy;
    }

    private function host(string $url): string
    {
        return (string) parse_url($url, PHP_URL_HOST);
    }
}
