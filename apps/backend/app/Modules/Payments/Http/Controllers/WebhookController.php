<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Jobs\ProcessPaymentWebhook;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\GatewayManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint público de webhooks de pago. Verifica la autenticidad, registra el
 * evento de forma idempotente (un provider_event_id una sola vez, docs/08) y lo
 * procesa en cola; responde rápido para que la pasarela no reintente.
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway, GatewayManager $manager): JsonResponse
    {
        $adapter = $manager->adapter($gateway);
        $record = $manager->record($gateway);

        if ($adapter === null || $record === null || ! $record->is_enabled) {
            return ApiResponse::error('Pasarela no disponible.', 'gateway_unavailable', status: 404);
        }

        if (! $adapter->verifyWebhook($request, $manager->credentials($record))) {
            return ApiResponse::error('Firma de webhook inválida.', 'invalid_signature', status: 401);
        }

        $event = $adapter->parseWebhook($request);
        if ($event->id === '') {
            return ApiResponse::error('Evento sin identificador.', 'invalid_event', status: 422);
        }

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
            // Ya recibido: no se reprocesa (no duplica cobros ni activaciones).
            return ApiResponse::message('Evento ya recibido.');
        }

        ProcessPaymentWebhook::dispatch($webhook->id);

        return ApiResponse::message('OK');
    }
}
