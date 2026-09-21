<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\AccessControl\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Ai\Database\Seeders\AiProviderSeeder;
use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Payments\Database\Seeders\PaymentGatewaySeeder;
use App\Modules\SocialConnections\Database\Seeders\SocialProviderSeeder;
use Illuminate\Database\Seeder;

/**
 * Datos base de la plataforma (sin usuarios): roles/permisos, planes/
 * entitlements, pasarelas de pago y proveedores sociales. Reutilizable por el
 * DatabaseSeeder y por los tests (se siembra una sola vez).
 */
class PlatformBaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BillingSeeder::class,
            PaymentGatewaySeeder::class,
            SocialProviderSeeder::class,
            AiProviderSeeder::class,
        ]);
    }
}
