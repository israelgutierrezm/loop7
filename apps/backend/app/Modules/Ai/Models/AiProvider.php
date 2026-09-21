<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de proveedores de IA. Las credenciales de plataforma se cifran
 * at-rest y nunca se exponen al frontend.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_enabled
 * @property bool $is_default
 * @property array<string, mixed>|null $config
 * @property array<string, string>|null $credentials
 */
class AiProvider extends Model
{
    protected $fillable = ['key', 'name', 'is_enabled', 'is_default', 'config', 'credentials'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
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

    public function defaultTextModel(): string
    {
        return (string) ($this->config['text_model'] ?? '');
    }

    public function defaultImageModel(): string
    {
        return (string) ($this->config['image_model'] ?? '');
    }

    /**
     * Coste en créditos de una operación: override por proveedor o valor por defecto.
     */
    public function creditCostFor(string $operation, int $default): int
    {
        $costs = $this->config['credit_costs'] ?? [];

        return isset($costs[$operation]) ? (int) $costs[$operation] : $default;
    }

    public function isConfigured(): bool
    {
        return $this->credentialMap() !== [];
    }
}
