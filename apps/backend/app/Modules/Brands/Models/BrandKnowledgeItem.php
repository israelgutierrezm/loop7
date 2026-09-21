<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property string $type
 * @property string $title
 */
class BrandKnowledgeItem extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = ['organization_id', 'brand_id', 'type', 'title', 'body', 'url', 'indexed_at'];

    protected function casts(): array
    {
        return ['indexed_at' => 'datetime'];
    }
}
