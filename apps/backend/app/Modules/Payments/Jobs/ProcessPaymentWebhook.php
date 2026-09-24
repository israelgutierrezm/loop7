<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\PaymentProcessor;
use App\Modules\Payments\Contracts\WebhookEvent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\GatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Procesa un webhook de pago ya verificado y registrado (idempotente por
 * gateway + provider_event_id). Traduce el evento a hechos de pago con el
 * adaptador y los aplica al billing; el resultado queda en el registro.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('default');
    }

    public function handle(GatewayManager $gateways, PaymentProcessor $processor, AuditLogger $audit): void
    {
        $event = PaymentWebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        $record = $gateways->record($event->gateway);
        $adapter = $gateways->adapter($event->gateway);
        if ($record === null || $adapter === null) {
            $event->update(['status' => 'failed', 'error' => 'Pasarela no disponible.']);

            return;
        }

        try {
            $notifications = $adapter->interpretWebhook(
                new WebhookEvent($event->provider_event_id, (string) $event->event_type, $event->payload ?? []),
                $gateways->credentials($record, $event->environment),
            );

            $results = [];
            foreach ($notifications as $notification) {
                $results[] = $processor->process($event->gateway, $event->environment, $notification);
            }
        } catch (Throwable $e) {
            $event->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000)]);

            throw $e; // reintento con backoff
        }

        $event->update([
            'status' => $notifications === [] ? 'ignored' : 'processed',
            'processed_at' => now(),
            'error' => null,
            'result' => $results === [] ? 'Evento sin efecto en el billing.' : Str::limit(implode(' | ', $results), 1000),
        ]);

        $audit->log(AuditAction::PAYMENT_WEBHOOK_PROCESSED, $event, [
            'gateway' => $event->gateway, 'type' => $event->event_type, 'results' => $results,
        ]);
    }
}
