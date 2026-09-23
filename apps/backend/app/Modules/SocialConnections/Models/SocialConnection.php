<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Models;

use App\Modules\Brands\Models\Brand;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property string $provider
 * @property ConnectionStatus $status
 * @property string|null $external_account_id
 * @property string|null $external_account_name
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property \Illuminate\Support\Carbon|null $token_expires_at
 * @property list<string>|null $scopes
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $last_health_check_at
 */
class SocialConnection extends Model
{
    use BelongsToOrganization;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'brand_id', 'provider', 'status',
        'external_account_id', 'external_account_name',
        'access_token', 'refresh_token', 'token_expires_at',
        'scopes', 'meta', 'connected_by_user_id', 'last_health_check_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
            'last_health_check_at' => 'datetime',
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
     * @return HasMany<SocialConnectionDestination, $this>
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(SocialConnectionDestination::class);
    }

    /**
     * @return HasMany<SocialTokenEvent, $this>
     */
    public function tokenEvents(): HasMany
    {
        return $this->hasMany(SocialTokenEvent::class);
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Tokens para operar con el proveedor; si se indica el destino, incluye su
     * token propio (p. ej. page token de Meta) para que el adaptador lo prefiera.
     */
    public function toTokens(?SocialConnectionDestination $destination = null): OAuthTokens
    {
        return new OAuthTokens(
            accessToken: (string) $this->access_token,
            refreshToken: $this->refresh_token,
            expiresAt: $this->token_expires_at,
            scopes: $this->scopes ?? [],
            destinationToken: $destination?->access_token,
        );
    }
}
