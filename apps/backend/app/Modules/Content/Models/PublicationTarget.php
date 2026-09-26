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
 * @property string|null $remote_url
 * @property string|null $error
 * @property array{fingerprint?: string, data?: array<string, mixed>}|null $provider_state
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property-read SocialConnectionDestination|null $destination
 */
class PublicationTarget extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'post_variant_id', 'social_connection_destination_id',
        'status', 'remote_id', 'remote_url', 'scheduled_at', 'published_at', 'error', 'provider_state',
    ];

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'provider_state' => 'array',
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
