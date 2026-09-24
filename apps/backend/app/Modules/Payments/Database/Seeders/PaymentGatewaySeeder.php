<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Seeders;

use App\Modules\Payments\Models\PaymentGateway;
use Illuminate\Database\Seeder;

/**
 * Pasarelas disponibles. Idempotente y NO destructivo: re-ejecutarlo no cambia
 * lo que SUPERADMIN configuró (habilitada, entorno, moneda, instrucciones).
 */
class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            ['key' => 'manual', 'name' => 'Manual', 'is_enabled' => true, 'config' => ['currency' => 'USD']],
            ['key' => 'stripe', 'name' => 'Stripe', 'is_enabled' => false, 'config' => ['currency' => 'USD']],
            ['key' => 'mercadopago', 'name' => 'Mercado Pago', 'is_enabled' => false, 'config' => ['currency' => 'MXN']],
            ['key' => 'openpay', 'name' => 'Openpay', 'is_enabled' => false, 'config' => ['currency' => 'MXN', 'country' => 'mx']],
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::query()->firstOrCreate(
                ['key' => $gateway['key']],
                [
                    'name' => $gateway['name'],
                    'is_enabled' => $gateway['is_enabled'],
                    'environment' => 'test',
                    'config' => $gateway['config'],
                ],
            );
        }
    }
}
