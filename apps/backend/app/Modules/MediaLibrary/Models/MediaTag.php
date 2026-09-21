<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
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
}
