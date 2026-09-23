<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Database\Seeders;

use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Database\Seeder;

/**
 * Catálogo de redes con adaptador implementado. Una red nueva se añade aquí al
 * implementar su `SocialProviderInterface` (ver docs/06).
 */
class SocialProviderSeeder extends Seeder
{
    public function run(): void
    {
        // El provider "fake" se habilita fuera de producción para desarrollo/pruebas.
        $fakeEnabled = ! app()->environment('production');

        $providers = [
            ['key' => 'fake', 'name' => 'Proveedor de prueba', 'is_enabled' => $fakeEnabled],
            ['key' => 'facebook', 'name' => 'Facebook', 'is_enabled' => false],
            ['key' => 'instagram', 'name' => 'Instagram', 'is_enabled' => false],
        ];

        foreach ($providers as $provider) {
            SocialProvider::query()->firstOrCreate(
                ['key' => $provider['key']],
                ['name' => $provider['name'], 'is_enabled' => $provider['is_enabled']],
            );
        }

        // Retira del catálogo las redes que ya no tienen adaptador.
        SocialProvider::query()
            ->whereNotIn('key', array_column($providers, 'key'))
            ->delete();
    }
}
