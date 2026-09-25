<?php

declare(strict_types=1);

namespace App\Modules\Api\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * API key con scopes. Tenant-owned. El secreto nunca se almacena en claro:
 * sólo su hash SHA-256 (docs/11 / seguridad CLAUDE.md).
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property string $name
 * @property string $prefix
 * @property string $token_hash
 * @property list<string> $scopes
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ApiKey extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'name', 'prefix', 'token_hash', 'scopes',
        'last_used_at', 'expires_at', 'is_active', 'created_by_user_id',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * Origen de una acción hecha con esta key, para la auditoría (nunca el secreto).
     *
     * @return array{via: string, api_key_id: string, api_key_name: string}
     */
    public function auditContext(string $via): array
    {
        return ['via' => $via, 'api_key_id' => $this->public_id, 'api_key_name' => $this->name];
    }
}
