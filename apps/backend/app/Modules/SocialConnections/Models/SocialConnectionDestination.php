<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $social_connection_id
 * @property string $external_id
 * @property string $name
 * @property string $type
 * @property array<string, bool>|null $capabilities
 * @property bool $is_active
 */
class SocialConnectionDestination extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'social_connection_id', 'external_id',
        'name', 'type', 'capabilities', 'metadata', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SocialConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'social_connection_id');
    }
}
