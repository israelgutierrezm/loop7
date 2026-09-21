<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Database\Seeders;

use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Database\Seeder;

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
            ['key' => 'threads', 'name' => 'Threads', 'is_enabled' => false],
            ['key' => 'linkedin', 'name' => 'LinkedIn', 'is_enabled' => false],
            ['key' => 'tiktok', 'name' => 'TikTok', 'is_enabled' => false],
            ['key' => 'x', 'name' => 'X', 'is_enabled' => false],
        ];

        foreach ($providers as $provider) {
            SocialProvider::query()->updateOrCreate(
                ['key' => $provider['key']],
                ['name' => $provider['name'], 'is_enabled' => $provider['is_enabled']],
            );
        }
    }
}
