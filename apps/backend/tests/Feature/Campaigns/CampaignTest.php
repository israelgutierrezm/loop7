<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
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
