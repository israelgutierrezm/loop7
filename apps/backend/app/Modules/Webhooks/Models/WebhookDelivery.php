<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrega de un mensaje a un endpoint, con el resultado de su último intento.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $webhook_endpoint_id
 * @property string $event
 * @property string $message_id
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $error
 * @property int|null $duration_ms
 * @property \Illuminate\Support\Carbon|null $next_attempt_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read WebhookEndpoint|null $endpoint
 */
class WebhookDelivery extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'webhook_endpoint_id', 'event', 'message_id', 'payload', 'status',
        'attempts', 'response_status', 'response_body', 'error', 'duration_ms',
        'next_attempt_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
