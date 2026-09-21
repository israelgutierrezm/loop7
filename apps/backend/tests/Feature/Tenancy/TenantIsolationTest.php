<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aislamiento estricto entre Organizations (docs/15 seguridad tenant / anti-IDOR).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_no_puede_ver_una_brand_de_otra_organizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');

        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrganization($ownerA, $orgA)
            ->getJson('/api/v1/brands/' . $brandB->public_id)
            ->assertNotFound();
    }

    public function test_el_listado_de_brands_esta_aislado_por_organizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');

        $brandA = Brand::factory()->create(['organization_id' => $orgA->id]);
        Brand::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrganization($ownerA, $orgA)
            ->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $brandA->public_id);
    }

    public function test_no_puede_operar_bajo_una_organizacion_de_la_que_no_es_miembro(): void
    {
        [$ownerA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');

        // ownerA intenta usar el contexto de orgB (no es miembro).
        $this->actingInOrganization($ownerA, $orgB)
            ->getJson('/api/v1/organization')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_not_resolved');
    }

    public function test_cambiar_el_id_en_la_url_no_evade_la_autorizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');

        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrganization($ownerA, $orgA)
            ->patchJson('/api/v1/brands/' . $brandB->public_id, ['name' => 'Hackeada'])
            ->assertNotFound();

        $this->assertDatabaseHas('brands', [
            'id' => $brandB->id,
            'name' => $brandB->name,
        ]);
    }
}
