<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int|null $plan_id
 * @property SubscriptionStatus $status
 * @property string|null $interval
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $gateway
 * @property string|null $gateway_subscription_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Plan|null $plan
 */
class Subscription extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'interval',
        'trial_ends_at', 'current_period_start', 'current_period_end',
        'cancel_at_period_end', 'cancelled_at', 'gateway', 'gateway_subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::TRIALING
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }
}
