<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property string $name
 * @property CampaignStatus $status
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 */
class Campaign extends Model
{
    use BelongsToOrganization;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'brand_id', 'name', 'description',
        'objective', 'status', 'starts_at', 'ends_at',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
