<?php

declare(strict_types=1);

namespace Tests\Feature\Brands;

use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zona horaria de la marca (base de sus mejores horarios, docs/05): hereda la de
 * la organización, se puede elegir y cambiar, y la migración corrige las marcas
 * que se crearon en UTC por defecto.
 */
class BrandTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_una_marca_nueva_hereda_la_zona_de_la_organizacion(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $org->update(['timezone' => 'America/Lima']);

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/brands', ['name' => 'Café Limeño'])
            ->assertCreated()
            ->assertJsonPath('data.timezone', 'America/Lima');
    }

    public function test_la_zona_elegida_se_respeta_y_se_puede_cambiar(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        $id = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/brands', ['name' => 'Tapas', 'timezone' => 'Europe/Madrid'])
            ->assertCreated()
            ->assertJsonPath('data.timezone', 'Europe/Madrid')
            ->json('data.id');

        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/brands/{$id}", ['timezone' => 'Atlantic/Canary'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Atlantic/Canary');

        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/brands/{$id}", ['timezone' => 'Marte/Olimpo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }

    public function test_la_migracion_pasa_las_marcas_en_utc_a_la_zona_de_su_organizacion(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $org->update(['timezone' => 'America/Santiago']);
        $defaulted = Brand::factory()->create(['organization_id' => $org->id, 'timezone' => 'UTC']);
        $chosen = Brand::factory()->create(['organization_id' => $org->id, 'timezone' => 'Europe/Madrid']);

        $migration = require base_path('app/Modules/Brands/Database/Migrations/2026_10_06_100001_default_brand_timezone_to_organization.php');
        $migration->up();

        $this->assertSame('America/Santiago', Brand::query()->withoutGlobalScopes()->findOrFail($defaulted->id)->timezone);
        $this->assertSame('Europe/Madrid', Brand::query()->withoutGlobalScopes()->findOrFail($chosen->id)->timezone);
    }
}
