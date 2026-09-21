<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string $name
 * @property string $entitlement_key
 * @property int $quantity_per_unit
 * @property int $price_cents
 * @property string $currency
 * @property bool $is_active
 */
class AddOn extends Model
{
    use HasPublicId;

    protected $table = 'add_ons';

    protected $fillable = [
        'key', 'name', 'description', 'entitlement_key',
        'quantity_per_unit', 'price_cents', 'currency', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'integer',
            'price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
