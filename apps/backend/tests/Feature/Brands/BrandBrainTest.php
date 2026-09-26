<?php

declare(strict_types=1);

namespace Tests\Feature\Brands;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandBrainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_el_owner_puede_definir_la_identidad_de_marca(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/brands/{$brand->public_id}/brain/guidelines", [
                'voice_tone' => 'Cercano y profesional',
                'value_propositions' => ['Ahorra tiempo', 'Todo en uno'],
                'hashtags' => ['#loop7', '#marketing'],
                'prohibited_terms' => ['barato'],
            ])
            ->assertOk()
            ->assertJsonPath('data.voice_tone', 'Cercano y profesional');

        $this->assertDatabaseHas('brand_guidelines', [
            'brand_id' => $brand->id,
            'voice_tone' => 'Cercano y profesional',
        ]);

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/brain")
            ->assertOk()
            ->assertJsonPath('data.guidelines.voice_tone', 'Cercano y profesional')
            ->assertJsonPath('data.guidelines.hashtags.0', '#loop7');
    }

    public function test_se_pueden_gestionar_audiencias_productos_y_knowledge(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/audiences", ['name' => 'PyMEs'])
            ->assertCreated();

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/products", ['name' => 'Plan Pro', 'price' => '99'])
            ->assertCreated();

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/knowledge", [
                'type' => 'faq',
                'title' => '¿Cómo cancelo?',
                'body' => 'Desde Facturación.',
            ])
            ->assertCreated();

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/brain")
            ->assertOk()
            ->assertJsonCount(1, 'data.audiences')
            ->assertJsonCount(1, 'data.products')
            ->assertJsonCount(1, 'data.knowledge');
    }

    public function test_se_edita_y_quita_un_elemento_sin_tocar_los_de_otra_marca(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $other = Brand::factory()->create(['organization_id' => $org->id]);
        $api = $this->actingInOrganization($owner, $org);

        $id = $api->postJson("/api/v1/brands/{$brand->public_id}/knowledge", ['type' => 'url', 'title' => 'Precios', 'url' => 'https://a.test'])
            ->assertCreated()->json('data.id');

        // Al pasar a FAQ, la interfaz envía la URL oculta como null.
        $api->patchJson("/api/v1/brands/{$brand->public_id}/knowledge/{$id}", [
            'type' => 'faq', 'title' => '¿Cuánto cuesta?', 'body' => 'Desde $99', 'url' => null,
        ])->assertOk()->assertJsonPath('data.title', '¿Cuánto cuesta?')->assertJsonPath('data.url', null);

        // El elemento sólo se resuelve dentro de su marca.
        $api->patchJson("/api/v1/brands/{$other->public_id}/knowledge/{$id}", ['type' => 'faq', 'title' => 'x'])->assertNotFound();
        $api->patchJson("/api/v1/brands/{$brand->public_id}/knowledge/{$id}", ['type' => 'faq', 'title' => ''])->assertStatus(422);

        $api->deleteJson("/api/v1/brands/{$brand->public_id}/knowledge/{$id}")->assertOk();
        $this->assertDatabaseMissing('brand_knowledge_items', ['public_id' => $id]);
    }

    public function test_viewer_no_puede_editar_la_identidad(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->putJson("/api/v1/brands/{$brand->public_id}/brain/guidelines", ['voice_tone' => 'x'])
            ->assertForbidden();
    }

    public function test_no_se_accede_al_brain_de_una_marca_de_otra_organizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');
        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrganization($ownerA, $orgA)
            ->getJson("/api/v1/brands/{$brandB->public_id}/brain")
            ->assertNotFound();
    }
}
