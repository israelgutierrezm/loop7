<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Enums\ContentType;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property int|null $campaign_id
 * @property string $title
 * @property string|null $body
 * @property ContentType $type
 * @property ContentStatus $status
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property int|null $created_by_user_id
 * @property int|null $approved_by_user_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property-read Campaign|null $campaign
 */
class ContentItem extends Model
{
    use BelongsToOrganization;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'brand_id', 'campaign_id', 'title', 'body',
        'type', 'status', 'scheduled_at', 'created_by_user_id',
        'approved_by_user_id', 'approved_at',
    ];

    protected $attributes = [
        'status' => 'draft',
        'type' => 'post',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'type' => ContentType::class,
            'scheduled_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return HasMany<PostVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    /**
     * @return HasMany<ContentComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ContentComment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
