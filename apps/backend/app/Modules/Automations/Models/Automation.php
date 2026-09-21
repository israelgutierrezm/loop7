<?php

declare(strict_types=1);

namespace App\Modules\Automations\Models;

use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Brands\Models\Brand;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regla de automatización. Tenant-owned: aislada por Organization.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int|null $brand_id
 * @property string $name
 * @property bool $is_enabled
 * @property AutomationTrigger $trigger
 * @property list<array{field: string, operator: string, value: string}>|null $conditions
 * @property list<array{type: string, config: array<string, mixed>}> $actions
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property int $run_count
 */
class Automation extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'brand_id', 'name', 'is_enabled', 'trigger',
        'conditions', 'actions', 'last_run_at', 'run_count', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'trigger' => AutomationTrigger::class,
            'conditions' => 'array',
            'actions' => 'array',
            'last_run_at' => 'datetime',
            'run_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
