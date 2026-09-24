<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Billing\Models\Subscription;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura/recibo interno. Estados: open (pendiente de pago), paid, void.
 * `meta` guarda el plan e intervalo que activa al pagarse.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int|null $subscription_id
 * @property string|null $gateway
 * @property string|null $gateway_reference
 * @property string $number
 * @property int $amount_cents
 * @property string $currency
 * @property string|null $description
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Invoice extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    public const OPEN = 'open';
    public const PAID = 'paid';
    public const VOID = 'void';

    protected $fillable = [
        'organization_id', 'subscription_id', 'gateway', 'gateway_reference', 'number',
        'amount_cents', 'currency', 'description', 'status', 'issued_at', 'paid_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
