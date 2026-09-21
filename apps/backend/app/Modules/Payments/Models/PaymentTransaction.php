<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $organization_id
 * @property string $gateway
 * @property string $environment
 * @property string|null $provider_transaction_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property string $type
 */
class PaymentTransaction extends Model
{
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'gateway', 'environment', 'provider_transaction_id',
        'amount_cents', 'currency', 'status', 'type', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'meta' => 'array',
        ];
    }
}
