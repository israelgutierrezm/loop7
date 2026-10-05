<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Canal de aviso configurable por SUPERADMIN (`webpush`, `whatsapp`). Las
 * credenciales se cifran en reposo y nunca se exponen al frontend.
 *
 * @property int $id
 * @property string $key
 * @property bool $is_enabled
 * @property array<string, mixed>|null $config
 * @property array<string, string>|null $credentials
 */
class NotificationChannel extends Model
{
    public const WEB_PUSH = 'webpush';

    public const WHATSAPP = 'whatsapp';

    protected $fillable = ['key', 'is_enabled', 'config', 'credentials'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }

    public function setting(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    public function secret(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }
}
