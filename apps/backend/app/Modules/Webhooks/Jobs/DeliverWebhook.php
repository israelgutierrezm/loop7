<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Jobs;

use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Services\WebhookDeliverer;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Entrega un webhook. Los reintentos por fallo del receptor se reprograman
 * con release() y se cuentan en la propia entrega; un endpoint caído nunca
 * llena la tabla de trabajos fallidos. Cola propia (`webhooks`) para que un
 * receptor lento no retrase la publicación ni el inbox.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Margen sobre los intentos de la entrega por si el worker se reinicia a mitad. */
    public int $tries = WebhookDeliverer::MAX_ATTEMPTS + 3;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookDeliverer $deliverer): void
    {
        $delay = $deliverer->deliver($this->deliveryId);

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    /**
     * Si el job se agota por caídas del worker, la entrega no queda pendiente para siempre.
     */
    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($this->deliveryId)
            ->where('status', DeliveryStatus::PENDING->value)
            ->update([
                'status' => DeliveryStatus::FAILED->value,
                'next_attempt_at' => null,
                'error' => 'La entrega se interrumpió repetidamente y se abandonó.',
            ]);
    }
}
