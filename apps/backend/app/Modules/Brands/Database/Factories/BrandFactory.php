<?php

declare(strict_types=1);

namespace App\Modules\Brands\Database\Factories;

use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            'website' => fake()->url(),
            'description' => fake()->sentence(),
            'timezone' => 'America/Mexico_City',
            'status' => BrandStatus::ACTIVE,
        ];
    }
}
