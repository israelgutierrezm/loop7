<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de proveedores sociales. Las credenciales (client_id/secret) se
 * cifran at-rest y no se exponen al frontend.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_enabled
 * @property array<string, mixed>|null $config
 * @property array<string, string>|null $credentials
 */
class SocialProvider extends Model
{
    protected $fillable = ['key', 'name', 'is_enabled', 'config', 'credentials'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function credentialMap(): array
    {
        return $this->credentials ?? [];
    }
}
