<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property int $social_connection_id
 * @property string $event
 * @property string|null $detail
 */
class SocialTokenEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'social_connection_id', 'event', 'detail'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
