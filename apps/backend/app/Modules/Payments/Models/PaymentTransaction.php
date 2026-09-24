<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de dinero registrado desde una pasarela (cobro o reembolso).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $organization_id
 * @property int|null $invoice_id
 * @property string $gateway
 * @property string $environment
 * @property string|null $provider_transaction_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property string $type
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class PaymentTransaction extends Model
{
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'invoice_id', 'gateway', 'environment', 'provider_transaction_id',
        'amount_cents', 'currency', 'status', 'type', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
