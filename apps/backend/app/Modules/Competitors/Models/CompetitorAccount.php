<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta pública de un competidor en una red (@usuario en Instagram, página de
 * Facebook, perfil de Threads…). Se sincroniza a diario.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $competitor_id
 * @property string $provider
 * @property string $handle
 * @property string|null $external_id
 * @property string|null $display_name
 * @property string|null $avatar_url
 * @property string|null $profile_url
 * @property string $status
 * @property string|null $last_error
 * @property int $failures
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property-read Competitor|null $competitor
 */
class CompetitorAccount extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'organization_id', 'competitor_id', 'provider', 'handle', 'external_id', 'display_name',
        'avatar_url', 'profile_url', 'status', 'last_error', 'failures', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'failures' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Competitor, $this>
     */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    /**
     * @return HasMany<CompetitorSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(CompetitorSnapshot::class);
    }

    /**
     * @return HasMany<CompetitorPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(CompetitorPost::class);
    }
}
