<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 * @property int $trial_days
 */
class Plan extends Model
{
    use HasPublicId;

    protected $fillable = [
        'key', 'name', 'description', 'is_active', 'is_public', 'sort_order', 'trial_days',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
            'trial_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * @return HasMany<PlanEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function priceFor(string $interval, string $currency = 'USD'): ?PlanPrice
    {
        return $this->prices
            ->firstWhere(fn (PlanPrice $p) => $p->interval === $interval && $p->currency === $currency);
    }
}
