<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $interval
 * @property string $currency
 * @property int $amount_cents
 * @property bool $is_active
 */
class PlanPrice extends Model
{
    protected $fillable = ['plan_id', 'interval', 'currency', 'amount_cents', 'is_active'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
