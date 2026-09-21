<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $entitlement_key
 * @property string $value
 */
class PlanEntitlement extends Model
{
    protected $fillable = ['plan_id', 'entitlement_key', 'value'];
}
