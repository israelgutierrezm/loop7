<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property int|null $media_asset_id
 * @property string $title
 */
class BrandDocument extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = ['organization_id', 'brand_id', 'media_asset_id', 'title', 'description', 'indexed_at'];

    protected function casts(): array
    {
        return ['indexed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
