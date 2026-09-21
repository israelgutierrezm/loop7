<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Inbox\Services\InboxService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: Organization, 1: Brand}
     */
    private function connectedBrand(): array
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

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

        return [$org, $brand];
    }

    private function syncedConversation(Organization $org, Brand $brand): InboxConversation
    {
        app(InboxService::class)->syncBrand($brand);

        return InboxConversation::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
    }

    public function test_sincroniza_conversaciones_de_muestra(): void
    {
        [$org, $brand] = $this->connectedBrand();

        $counts = app(InboxService::class)->syncBrand($brand);

        $this->assertSame(3, $counts['conversations']);
        $this->assertGreaterThan(0, $counts['messages']);
        $this->assertDatabaseHas('inbox_conversations', ['brand_id' => $brand->id, 'provider' => 'fake']);
        $this->assertDatabaseHas('inbox_messages', ['type' => 'inbound']);
    }

    public function test_sincronizar_es_idempotente(): void
    {
        [$org, $brand] = $this->connectedBrand();

        app(InboxService::class)->syncBrand($brand);
        app(InboxService::class)->syncBrand($brand);

        $this->assertSame(3, InboxConversation::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->count());
    }

    public function test_index_lista_conversaciones(): void
    {
        [$org, $brand] = $this->connectedBrand();
        app(InboxService::class)->syncBrand($brand);
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/inbox")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_show_marca_como_leida(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $conv = $this->syncedConversation($org, $brand);
        $this->assertGreaterThan(0, $conv->unread_count);
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/inbox/{$conv->public_id}")
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->assertSame(0, $conv->fresh()->unread_count);
    }

    public function test_responder_envia_y_registra(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $conv = $this->syncedConversation($org, $brand);
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conv->public_id}/reply", ['body' => 'Gracias por tu mensaje 😊'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'reply');

        $this->assertDatabaseHas('inbox_messages', ['conversation_id' => $conv->id, 'type' => 'reply']);
    }

    public function test_nota_interna(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $conv = $this->syncedConversation($org, $brand);
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conv->public_id}/note", ['body' => 'Cliente recurrente'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'note');
    }

    public function test_asignar_y_cambiar_estado(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $conv = $this->syncedConversation($org, $brand);
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conv->public_id}/assign", ['assignee' => $owner->public_id])
            ->assertOk()
            ->assertJsonPath('data.assignee', $owner->name);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conv->public_id}/status", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertDatabaseHas('inbox_conversations', ['id' => $conv->id, 'status' => 'resolved']);
    }

    public function test_plan_sin_inbox_devuelve_402(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $this->setOrganizationPlan($org, 'starter'); // sin feature.inbox
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/inbox")
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.inbox');
    }

    public function test_rol_sin_permiso_no_accede(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/inbox")
            ->assertForbidden();
    }

    public function test_no_accede_a_conversacion_de_otra_organizacion(): void
    {
        [$orgA] = $this->connectedBrand();
        [$orgB, $brandB] = $this->connectedBrand();
        $convB = $this->syncedConversation($orgB, $brandB);
        $ownerA = $orgA->owner;

        $this->actingInOrganization($ownerA, $orgA)
            ->getJson("/api/v1/inbox/{$convB->public_id}")
            ->assertNotFound();
    }

    public function test_sugerir_respuesta_con_ia(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $conv = $this->syncedConversation($org, $brand);
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conv->public_id}/suggest")
            ->assertOk()
            ->assertJsonPath('data.credits', 2);

        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $org->id, 'operation' => 'suggest_reply']);
    }
}
