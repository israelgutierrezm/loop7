<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro idempotente de webhooks de pago (unique gateway + provider_event_id).
 * Estados: received → processed | ignored | failed.
 *
 * @property int $id
 * @property string $gateway
 * @property string $environment
 * @property string $provider_event_id
 * @property string|null $event_type
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property string|null $error
 * @property string|null $result
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'gateway', 'environment', 'provider_event_id', 'event_type',
        'payload', 'status', 'processed_at', 'error', 'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
