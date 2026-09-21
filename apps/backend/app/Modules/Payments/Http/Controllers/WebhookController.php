<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\GatewayManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint público de webhooks de pago. Verifica firma y procesa de forma
 * idempotente (un provider_event_id se procesa una sola vez, docs/08 y docs/15).
 */
class WebhookController extends Controller
{
    public function handle(
        Request $request,
        string $gateway,
        GatewayManager $manager,
        AuditLogger $audit,
    ): JsonResponse {
        $adapter = $manager->adapter($gateway);
        $record = $manager->record($gateway);

        if ($adapter === null || $record === null || ! $record->is_enabled) {
            return ApiResponse::error('Pasarela no disponible.', 'gateway_unavailable', status: 404);
        }

        $credentials = $record->credentialMap($record->environment);

        if (! $adapter->verifyWebhook($request, $credentials)) {
            return ApiResponse::error('Firma de webhook inválida.', 'invalid_signature', status: 401);
        }

        $event = $adapter->parseWebhook($request);
        if ($event->id === '') {
            return ApiResponse::error('Evento sin identificador.', 'invalid_event', status: 422);
        }

        // Idempotencia: firstOrCreate sobre (gateway, provider_event_id).
        $webhook = PaymentWebhookEvent::query()->firstOrCreate(
            ['gateway' => $gateway, 'provider_event_id' => $event->id],
            [
                'environment' => $record->environment,
                'event_type' => $event->type,
                'payload' => $event->data,
                'status' => 'received',
            ],
        );

        if (! $webhook->wasRecentlyCreated) {
            // Ya recibido antes: no se reprocesa (no duplica cobros ni suscripciones).
            return ApiResponse::message('Evento ya procesado.');
        }

        // Aquí se despacharía un Job para procesar el evento (Fase 6, colas).
        $webhook->update(['status' => 'processed', 'processed_at' => now()]);

        $audit->log(
            AuditAction::PAYMENT_WEBHOOK_PROCESSED,
            $webhook,
            ['gateway' => $gateway, 'type' => $event->type],
        );

        return ApiResponse::message('OK');
    }
}
