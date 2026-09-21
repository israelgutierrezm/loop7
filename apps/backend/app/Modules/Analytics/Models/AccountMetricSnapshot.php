<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot diario de métricas de cuenta. Tenant-owned: aislado por Organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $brand_id
 * @property int $social_connection_destination_id
 * @property string $provider
 * @property \Illuminate\Support\Carbon $date
 * @property int $followers
 * @property int $reach
 * @property int $impressions
 * @property int $engagement
 * @property int $posts_count
 */
class AccountMetricSnapshot extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'brand_id', 'social_connection_destination_id', 'provider',
        'date', 'followers', 'reach', 'impressions', 'engagement', 'posts_count',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'followers' => 'integer',
            'reach' => 'integer',
            'impressions' => 'integer',
            'engagement' => 'integer',
            'posts_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SocialConnectionDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(SocialConnectionDestination::class, 'social_connection_destination_id');
    }
}
