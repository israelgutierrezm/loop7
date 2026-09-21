<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int|null $subscription_id
 * @property string $number
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
class Invoice extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'subscription_id', 'number', 'amount_cents',
        'currency', 'status', 'issued_at', 'paid_at', 'meta',
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
}
