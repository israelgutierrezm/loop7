<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Etiqueta de la biblioteca de una Brand (única por nombre dentro de la Brand).
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property string $name
 */
class MediaTag extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = ['organization_id', 'brand_id', 'name'];

    /**
     * @return BelongsToMany<MediaAsset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'media_asset_tags')->withTimestamps();
    }
}
