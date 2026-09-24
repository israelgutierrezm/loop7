<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Gateways\ManualGateway;
use App\Modules\Payments\Gateways\MercadoPagoGateway;
use App\Modules\Payments\Gateways\OpenpayGateway;
use App\Modules\Payments\Gateways\StripeGateway;
use App\Modules\Payments\Models\PaymentGateway;
use Illuminate\Support\Collection;

/**
 * Registro de adaptadores de pasarela y acceso a su configuración persistida.
 */
class GatewayManager
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            'manual' => new ManualGateway(),
            'stripe' => new StripeGateway(),
            'mercadopago' => new MercadoPagoGateway(),
            'openpay' => new OpenpayGateway(),
        ];
    }

    public function adapter(string $key): ?PaymentGatewayInterface
    {
        return $this->adapters[$key] ?? null;
    }

    /**
     * @return array<string, PaymentGatewayInterface>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }

    public function record(string $key): ?PaymentGateway
    {
        return PaymentGateway::query()->where('key', $key)->first();
    }

    /**
     * Credenciales (descifradas) del entorno activo + entorno + ajustes no
     * secretos de la pasarela (moneda, país, instrucciones de pago manual).
     *
     * @return array<string, string>
     */
    public function credentials(PaymentGateway $gateway, ?string $environment = null): array
    {
        $environment ??= $gateway->environment;
        $credentials = $gateway->credentialMap($environment);

        foreach (['currency', 'country', 'instructions'] as $setting) {
            $value = $gateway->config[$setting] ?? null;
            if (is_string($value) && $value !== '') {
                $credentials[$setting] = $value;
            }
        }
        $credentials['environment'] = $environment;

        return $credentials;
    }

    /**
     * Moneda en la que cobra la pasarela (los planes deben tener precio en ella).
     */
    public function currency(PaymentGateway $gateway): string
    {
        $currency = $gateway->config['currency'] ?? null;

        return is_string($currency) && $currency !== '' ? mb_strtoupper($currency) : 'USD';
    }

    /**
     * Registros de pasarelas habilitadas (para el cliente).
     *
     * @return Collection<int, PaymentGateway>
     */
    public function enabled(): Collection
    {
        return PaymentGateway::query()->where('is_enabled', true)->get();
    }

    public function isEnabled(string $key): bool
    {
        return PaymentGateway::query()->where('key', $key)->where('is_enabled', true)->exists();
    }
}
