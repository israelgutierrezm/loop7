<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function createContent(int $orgId, int $brandId, string $status = 'draft'): ContentItem
    {
        return ContentItem::query()->create([
            'organization_id' => $orgId,
            'brand_id' => $brandId,
            'title' => 'Pieza',
            'status' => $status,
        ]);
    }

    public function test_content_creator_crea_y_envia_pero_no_aprueba(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);

        $id = $this->actingInOrganization($creator, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/content", ['title' => 'Idea'])
            ->assertCreated()
            ->json('data.id');

        $this->actingInOrganization($creator, $org)
            ->postJson("/api/v1/content/{$id}/submit")
            ->assertOk();

        // El creador NO puede aprobar.
        $this->actingInOrganization($creator, $org)
            ->postJson("/api/v1/content/{$id}/approve")
            ->assertForbidden();

        $this->assertDatabaseHas('content_items', ['public_id' => $id, 'status' => 'in_review']);
    }

    public function test_approver_aprueba_contenido_en_revision(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = $this->createContent($org->id, $brand->id, 'in_review');
        $approver = $this->addMember($org, OrganizationRole::APPROVER->value);

        $this->actingInOrganization($approver, $org)
            ->postJson("/api/v1/content/{$content->public_id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'status' => 'approved']);
    }

    public function test_solicitar_cambios_registra_comentario(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = $this->createContent($org->id, $brand->id, 'in_review');

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/request-changes", ['note' => 'Ajusta el tono'])
            ->assertOk();

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'status' => 'changes_requested']);
        $this->assertDatabaseHas('content_comments', ['content_item_id' => $content->id, 'body' => 'Ajusta el tono']);
    }

    public function test_programar_requiere_aprobado_y_crea_targets(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        // Conexión + destino fake para la marca.
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'provider' => 'fake',
            'status' => 'connected',
            'external_account_name' => 'Demo',
        ]);
        SocialConnectionDestination::query()->create([
            'organization_id' => $org->id,
            'social_connection_id' => $connection->id,
            'external_id' => 'dest-1',
            'name' => 'Página',
            'type' => 'page',
        ]);

        // Contenido aprobado con variante del proveedor fake.
        $content = $this->createContent($org->id, $brand->id, 'approved');
        $content->variants()->create([
            'organization_id' => $org->id,
            'provider' => 'fake',
            'body' => 'Hola',
            'format' => 'text',
        ]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/schedule", [
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertOk();

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'status' => 'scheduled']);
        $this->assertDatabaseCount('publication_targets', 1);
    }

    public function test_no_se_programa_contenido_sin_aprobar(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = $this->createContent($org->id, $brand->id, 'draft');

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/schedule", [
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422);
    }
}
