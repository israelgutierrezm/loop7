<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_crear_contenido_con_variantes(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/content", [
                'title' => 'Lanzamiento',
                'body' => 'Texto base',
                'variants' => [
                    ['provider' => 'facebook', 'body' => 'Versión FB', 'format' => 'text'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(1, 'data.variants');

        $this->assertDatabaseHas('content_items', ['brand_id' => $brand->id, 'title' => 'Lanzamiento']);
        $this->assertDatabaseCount('post_variants', 1);
    }

    public function test_viewer_no_puede_crear_contenido(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/content", ['title' => 'X'])
            ->assertForbidden();
    }

    public function test_no_se_accede_a_contenido_de_otra_organizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');
        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);
        $contentB = ContentItem::query()->create([
            'organization_id' => $orgB->id,
            'brand_id' => $brandB->id,
            'title' => 'Privado B',
        ]);

        $this->actingInOrganization($ownerA, $orgA)
            ->getJson("/api/v1/content/{$contentB->public_id}")
            ->assertNotFound();
    }

    public function test_no_se_edita_contenido_en_revision(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'title' => 'En revisión',
            'status' => 'in_review',
        ]);

        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/content/{$content->public_id}", ['title' => 'Nuevo'])
            ->assertStatus(422);
    }
}
