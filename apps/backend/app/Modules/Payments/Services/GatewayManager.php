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
