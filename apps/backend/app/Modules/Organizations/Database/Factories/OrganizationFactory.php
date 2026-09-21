<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Database\Factories;

use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            'status' => OrganizationStatus::ACTIVE,
            'billing_email' => fake()->companyEmail(),
            'country' => 'MX',
            'timezone' => 'America/Mexico_City',
            'locale' => 'es',
        ];
    }
}
