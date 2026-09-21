<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\Content\Models\PublicationTarget;
use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot diario de métricas por publicación. Tenant-owned: aislado por Organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $brand_id
 * @property int $publication_target_id
 * @property string $provider
 * @property string|null $remote_id
 * @property \Illuminate\Support\Carbon $date
 * @property int $impressions
 * @property int $reach
 * @property int $likes
 * @property int $comments
 * @property int $shares
 * @property int $clicks
 * @property int $engagement
 */
class PostMetricSnapshot extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'brand_id', 'publication_target_id', 'provider', 'remote_id',
        'date', 'impressions', 'reach', 'likes', 'comments', 'shares', 'clicks', 'engagement',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'impressions' => 'integer',
            'reach' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'clicks' => 'integer',
            'engagement' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PublicationTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PublicationTarget::class, 'publication_target_id');
    }
}
