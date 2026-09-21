<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Seeders;

use App\Modules\Payments\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            ['key' => 'manual', 'name' => 'Manual', 'is_enabled' => true],
            ['key' => 'stripe', 'name' => 'Stripe', 'is_enabled' => false],
            ['key' => 'mercadopago', 'name' => 'Mercado Pago', 'is_enabled' => false],
            ['key' => 'openpay', 'name' => 'Openpay', 'is_enabled' => false],
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::query()->updateOrCreate(
                ['key' => $gateway['key']],
                ['name' => $gateway['name'], 'is_enabled' => $gateway['is_enabled'], 'environment' => 'test'],
            );
        }
    }
}
