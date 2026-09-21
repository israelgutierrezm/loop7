<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada de uso de IA (docs/07). Tenant-owned: aislada por Organization.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int|null $brand_id
 * @property int|null $user_id
 * @property string $provider
 * @property string|null $model
 * @property string $modality
 * @property string $operation
 * @property int $units
 * @property int $credits
 * @property int $cost_cents
 * @property int $latency_ms
 * @property string $status
 * @property bool $byok
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class AiUsageLog extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'brand_id', 'user_id', 'provider', 'model', 'modality',
        'operation', 'units', 'credits', 'cost_cents', 'latency_ms', 'status', 'byok',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'credits' => 'integer',
            'cost_cents' => 'integer',
            'latency_ms' => 'integer',
            'byok' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
