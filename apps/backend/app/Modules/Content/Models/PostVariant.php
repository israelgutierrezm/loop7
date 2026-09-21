<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $content_item_id
 * @property string $provider
 * @property string|null $body
 * @property string $format
 * @property array<string, mixed>|null $options
 */
class PostVariant extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'content_item_id', 'provider', 'body', 'format', 'options',
    ];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /**
     * @return BelongsToMany<MediaAsset, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'post_variant_media')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return HasMany<PublicationTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PublicationTarget::class);
    }
}
