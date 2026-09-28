<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Support\Security\OutboundUrl;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Envía entregas de webhooks: POST firmado, anti-SSRF (IP fijada, sin seguir
 * redirecciones), reintentos con espera creciente y desactivación automática
 * del endpoint si sus entregas fallan una y otra vez.
 */
final class WebhookDeliverer
{
    /** Intentos por entrega (el primero inmediato). */
    public const MAX_ATTEMPTS = 7;

    /** Espera antes de cada reintento: 1 min, 5 min, 30 min, 2 h, 6 h y 12 h (~21 h en total). */
    private const BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600, 43200];

    /** Entregas fallidas seguidas (tras agotar reintentos) que desactivan el endpoint. */
    public const DISABLE_AFTER_FAILURES = 15;

    private const TIMEOUT_SECONDS = 10;

    private const RESPONSE_BODY_BYTES = 1000;

    public function __construct(
        private readonly WebhookSigner $signer,
        private readonly EntitlementsService $entitlements,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * Procesa una entrega pendiente. Devuelve los segundos hasta el siguiente
     * intento, o null si ya terminó (entregada, fallida o no procede).
     */
    public function deliver(int $deliveryId): ?int
    {
        $delivery = WebhookDelivery::query()->withoutGlobalScope(OrganizationScope::class)->find($deliveryId);
        // Idempotente: una entrega ya resuelta no se vuelve a enviar.
        if ($delivery === null || $delivery->status !== DeliveryStatus::PENDING) {
            return null;
        }

        $endpoint = WebhookEndpoint::query()->withoutGlobalScope(OrganizationScope::class)->find($delivery->webhook_endpoint_id);
        if ($endpoint === null || ! $endpoint->is_active) {
            $this->finish($delivery, DeliveryStatus::FAILED, 'El endpoint está desactivado: no se envió.');

            return null;
        }

        $organization = Organization::query()->find($delivery->organization_id);
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_API)) {
            $this->finish($delivery, DeliveryStatus::FAILED, 'Tu plan ya no incluye la API ni los webhooks.');

            return null;
        }

        if ($this->attempt($delivery, $endpoint)) {
            $this->finish($delivery, DeliveryStatus::SUCCEEDED);
            $endpoint->forceFill(['consecutive_failures' => 0, 'last_delivery_at' => now()])->save();

            return null;
        }

        if ($delivery->attempts < self::MAX_ATTEMPTS) {
            $delay = self::BACKOFF_SECONDS[$delivery->attempts - 1] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)];
            $delivery->forceFill(['next_attempt_at' => now()->addSeconds($delay)])->save();

            return $delay;
        }

        $this->finish($delivery, DeliveryStatus::FAILED);
        $this->recordFailure($endpoint);

        return null;
    }

    /**
     * Un intento de envío (sin reintentos). Guarda el resultado en la entrega
     * y devuelve si el endpoint respondió 2xx.
     */
    public function attempt(WebhookDelivery $delivery, WebhookEndpoint $endpoint): bool
    {
        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $started = hrtime(true);
        $status = null;
        $responseBody = null;

        try {
            // Anti-SSRF: se revalida en cada intento (el DNS pudo cambiar) y se fija la IP.
            $target = OutboundUrl::resolve($endpoint->url);

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(5)
                ->withOptions(OutboundUrl::pinnedOptions($target))
                ->withUserAgent('Loop7-Webhooks/1.0')
                ->withHeaders([
                    'webhook-id' => $delivery->message_id,
                    'webhook-timestamp' => (string) $timestamp,
                    'webhook-signature' => $this->signer->header($endpoint->signingSecrets(), $delivery->message_id, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $status = $response->status();
            $responseBody = mb_scrub(mb_strcut($response->body(), 0, self::RESPONSE_BODY_BYTES));
            $ok = $response->successful();
            $error = match (true) {
                $ok => null,
                $response->redirect() => "El endpoint respondió con una redirección ({$status}): usa la URL final.",
                default => "El endpoint respondió {$status}.",
            };
        } catch (InvalidArgumentException $e) {
            $ok = false;
            $error = $e->getMessage();
        } catch (ConnectionException $e) {
            $ok = false;
            $error = 'No se pudo conectar con el endpoint: ' . $this->connectionError($e->getMessage());
        }

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'response_status' => $status,
            'response_body' => $responseBody,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ])->save();

        return $ok;
    }

    private function finish(WebhookDelivery $delivery, DeliveryStatus $status, ?string $error = null): void
    {
        $delivery->forceFill([
            'status' => $status->value,
            'next_attempt_at' => null,
            'delivered_at' => $status === DeliveryStatus::SUCCEEDED ? now() : null,
            ...($error !== null ? ['error' => $error] : []),
        ])->save();
    }

    /**
     * Cuenta la entrega fallida y, si el endpoint acumula demasiadas seguidas,
     * lo desactiva y avisa a quien gestiona la API.
     */
    private function recordFailure(WebhookEndpoint $endpoint): void
    {
        WebhookEndpoint::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($endpoint->id)
            ->update(['consecutive_failures' => DB::raw('consecutive_failures + 1'), 'last_delivery_at' => now()]);
        $endpoint->refresh();

        if (! $endpoint->is_active || $endpoint->consecutive_failures < self::DISABLE_AFTER_FAILURES) {
            return;
        }

        $endpoint->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => 'Se desactivó tras ' . self::DISABLE_AFTER_FAILURES . ' entregas fallidas seguidas.',
        ])->save();

        $host = (string) parse_url($endpoint->url, PHP_URL_HOST);
        $this->audit->log(
            AuditAction::WEBHOOK_ENDPOINT_DISABLED,
            $endpoint,
            ['host' => $host, 'consecutive_failures' => $endpoint->consecutive_failures],
            organizationId: $endpoint->organization_id,
        );

        $this->notifier->toMembersWithPermission(
            Permission::API_MANAGE,
            new OrganizationNotice(
                organizationId: $endpoint->organization_id,
                kind: 'webhook.endpoint_disabled',
                category: NotificationCategory::INTEGRATIONS,
                title: 'Webhook desactivado',
                body: "El webhook hacia {$host} dejó de responder y se desactivó. Revisa el servidor y vuelve a activarlo en API y accesos.",
                path: '/app/api-keys?tab=webhooks',
                level: 'warning',
            ),
        );
    }

    /**
     * Mensaje de cURL sin la URL ni la referencia a la documentación.
     */
    private function connectionError(string $message): string
    {
        if (preg_match('/cURL error \d+: ([^(]+)/', $message, $m) === 1) {
            return trim($m[1]);
        }

        return 'tiempo de espera agotado o conexión rechazada.';
    }
}
