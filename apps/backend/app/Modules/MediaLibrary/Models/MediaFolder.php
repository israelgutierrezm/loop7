<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property int|null $parent_id
 * @property string $name
 */
class MediaFolder extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = ['organization_id', 'brand_id', 'parent_id', 'name'];

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }
}
