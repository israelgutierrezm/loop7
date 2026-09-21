<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Modules\Content\Enums\TargetStatus;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $post_variant_id
 * @property int|null $social_connection_destination_id
 * @property TargetStatus $status
 * @property string|null $remote_id
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 */
class PublicationTarget extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'post_variant_id', 'social_connection_destination_id',
        'status', 'remote_id', 'remote_url', 'scheduled_at', 'published_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PostVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }

    /**
     * @return BelongsTo<SocialConnectionDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(SocialConnectionDestination::class, 'social_connection_destination_id');
    }
}
