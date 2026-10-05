<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Navegador en el que un usuario activó los avisos push (Web Push). El
 * endpoint lo da el servicio push del navegador; sólo se admiten los conocidos.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'user_agent', 'last_used_at'];

    protected $hidden = ['auth_token', 'public_key'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
