<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AccessControl\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Organizations\Actions\CreateOrganizationForUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Permisos y roles globales.
        $this->call(RolesAndPermissionsSeeder::class);

        // 2) SUPERADMIN de plataforma.
        User::query()->firstOrCreate(
            ['email' => 'superadmin@loop7.test'],
            [
                'name' => 'Super Administrador',
                'password' => Hash::make('Superadmin123'),
                'is_platform_admin' => true,
                'email_verified_at' => now(),
                'locale' => 'es',
            ],
        );

        // 3) Usuario demo con su Organization (sólo fuera de producción).
        if (! app()->environment('production')) {
            $owner = User::query()->firstOrCreate(
                ['email' => 'owner@loop7.test'],
                [
                    'name' => 'Dueño Demo',
                    'password' => Hash::make('Owner12345'),
                    'email_verified_at' => now(),
                    'locale' => 'es',
                ],
            );

            if ($owner->ownedOrganizations()->count() === 0) {
                app(CreateOrganizationForUser::class)->handle($owner, 'Organización Demo');
            }
        }
    }
}
