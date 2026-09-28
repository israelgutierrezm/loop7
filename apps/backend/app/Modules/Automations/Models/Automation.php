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
 * @property array{feed_url?: string}|null $trigger_config
 * @property string|null $inbound_token
 * @property string|null $inbound_token_hash
 * @property list<array{field: string, operator: string, value: string}>|null $conditions
 * @property list<array{type: string, config: array<string, mixed>}> $actions
 * @property array<string, mixed>|null $state
 * @property \Illuminate\Support\Carbon|null $polled_at
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property int $run_count
 * @property int|null $created_by_user_id
 * @property-read Brand|null $brand
 */
class Automation extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'brand_id', 'name', 'is_enabled', 'trigger', 'trigger_config',
        'conditions', 'actions', 'last_run_at', 'run_count', 'created_by_user_id',
    ];

    protected $hidden = ['inbound_token', 'inbound_token_hash'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'trigger' => AutomationTrigger::class,
            'trigger_config' => 'array',
            'inbound_token' => 'encrypted',
            'state' => 'array',
            'polled_at' => 'datetime',
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

    /**
     * URL pública del webhook entrante (lleva el token secreto).
     */
    public function inboundUrl(): ?string
    {
        if ($this->trigger !== AutomationTrigger::WEBHOOK_RECEIVED || $this->inbound_token === null) {
            return null;
        }

        return rtrim((string) config('app.url'), '/') . '/api/v1/hooks/automations/' . $this->inbound_token;
    }

    public function feedUrl(): ?string
    {
        $url = $this->trigger_config['feed_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
