<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Content\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_owner_crea_y_lista_campanas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/campaigns", [
                'name' => 'Black Friday',
                'objective' => 'Ventas',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Black Friday');

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/campaigns")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_viewer_no_puede_crear_campanas(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/campaigns", ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_editar_cambiar_estado_eliminar_y_filtrar_contenido_por_campana(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $as = fn () => $this->actingInOrganization($owner, $org);

        $campaign = $as()->postJson("/api/v1/brands/{$brand->public_id}/campaigns", ['name' => 'Verano'])
            ->assertCreated()
            ->assertJsonPath('data.status_label', 'Borrador')
            ->json('data.id');

        $as()->patchJson("/api/v1/campaigns/{$campaign}", [
            'status' => 'active', 'starts_at' => '2026-10-01', 'ends_at' => '2026-09-01',
        ])->assertStatus(422); // fin antes del inicio
        $as()->patchJson("/api/v1/campaigns/{$campaign}", [
            'status' => 'active', 'objective' => 'Ventas', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        // Contenido de la campaña: al crear, al reasignar (aunque ya esté aprobado) y filtros.
        $inCampaign = $as()->postJson("/api/v1/brands/{$brand->public_id}/content", ['title' => 'Promo', 'campaign' => $campaign])
            ->assertCreated()->json('data.id');
        $loose = $as()->postJson("/api/v1/brands/{$brand->public_id}/content", ['title' => 'Suelto'])->json('data.id');
        ContentItem::query()->where('public_id', $loose)->update(['status' => 'approved']);
        $as()->patchJson("/api/v1/content/{$loose}", ['campaign' => $campaign])
            ->assertOk()->assertJsonPath('data.campaign.name', 'Verano');
        $as()->patchJson("/api/v1/content/{$loose}", ['title' => 'No'])->assertStatus(422); // el texto ya no se edita

        $as()->getJson("/api/v1/brands/{$brand->public_id}/campaigns")->assertJsonPath('data.0.content_count', 2);
        $as()->getJson("/api/v1/brands/{$brand->public_id}/content?campaign={$campaign}&q=prom")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inCampaign);

        // Al eliminarla, el contenido se conserva sin campaña.
        $as()->deleteJson("/api/v1/campaigns/{$campaign}")->assertOk();
        $as()->getJson("/api/v1/content/{$inCampaign}")->assertOk()->assertJsonPath('data.campaign', null);
    }

    public function test_no_se_edita_campana_de_otra_organizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');
        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);
        $campaignB = Campaign::query()->create([
            'organization_id' => $orgB->id,
            'brand_id' => $brandB->id,
            'name' => 'Campaña B',
        ]);

        $this->actingInOrganization($ownerA, $orgA)
            ->patchJson("/api/v1/campaigns/{$campaignB->public_id}", ['name' => 'Hack'])
            ->assertNotFound();
    }
}
