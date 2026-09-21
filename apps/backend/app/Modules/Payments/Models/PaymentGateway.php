<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_enabled
 * @property string $environment
 * @property array<string, mixed>|null $config
 */
class PaymentGateway extends Model
{
    protected $fillable = ['key', 'name', 'is_enabled', 'environment', 'config'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<PaymentGatewayCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(PaymentGatewayCredential::class);
    }

    /**
     * Credenciales (descifradas) del entorno indicado, como mapa key=>value.
     *
     * @return array<string, string>
     */
    public function credentialMap(?string $environment = null): array
    {
        $environment ??= $this->environment;

        return $this->credentials
            ->where('environment', $environment)
            ->mapWithKeys(fn (PaymentGatewayCredential $c) => [$c->key => $c->value])
            ->all();
    }
}
