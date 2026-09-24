<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brand Access: un miembro limitado a ciertas Brands no puede operar recursos
 * de otras Brands de la misma Organization aunque conozca su public_id.
 */
class BrandAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $limited;

    private Brand $allowed;

    private Brand $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        [$this->owner, $this->org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($this->org, 'agency'); // inbox + automatizaciones

        $this->allowed = Brand::factory()->create(['organization_id' => $this->org->id, 'name' => 'Permitida']);
        $this->other = Brand::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ajena']);

        $this->limited = $this->addMember($this->org, OrganizationRole::MANAGER->value, allBrandsAccess: false);
        $this->allowed->grantAccessTo($this->limited->id);
    }

    private function contentOf(Brand $brand): ContentItem
    {
        return ContentItem::query()->create([
            'organization_id' => $this->org->id, 'brand_id' => $brand->id, 'title' => 'Post de ' . $brand->name,
        ]);
    }

    public function test_contenido_de_otra_marca_no_se_ve_ni_se_opera(): void
    {
        $foreign = $this->contentOf($this->other);
        $variant = PostVariant::query()->create([
            'organization_id' => $this->org->id, 'content_item_id' => $foreign->id, 'provider' => 'facebook', 'body' => 'Hola',
        ]);
        $as = fn () => $this->actingInOrganization($this->limited, $this->org);

        $as()->getJson("/api/v1/content/{$foreign->public_id}")->assertForbidden();
        $as()->patchJson("/api/v1/content/{$foreign->public_id}", ['title' => 'X'])->assertForbidden();
        $as()->deleteJson("/api/v1/content/{$foreign->public_id}")->assertForbidden();
        $as()->postJson("/api/v1/content/{$foreign->public_id}/submit")->assertForbidden();
        $as()->postJson("/api/v1/content/{$foreign->public_id}/comments", ['body' => 'Hola'])->assertForbidden();
        $as()->postJson("/api/v1/content/{$foreign->public_id}/variants", ['provider' => 'facebook'])->assertForbidden();
        $as()->patchJson("/api/v1/variants/{$variant->public_id}", ['body' => 'Cambio'])->assertForbidden();
        $as()->deleteJson("/api/v1/variants/{$variant->public_id}")->assertForbidden();

        $this->assertDatabaseHas('content_items', ['id' => $foreign->id, 'title' => 'Post de Ajena', 'deleted_at' => null]);
        $this->assertDatabaseHas('post_variants', ['id' => $variant->id, 'body' => 'Hola']);

        // Su propia marca sí.
        $own = $this->contentOf($this->allowed);
        $as()->getJson("/api/v1/content/{$own->public_id}")->assertOk()->assertJsonPath('data.brand', $this->allowed->public_id);
    }

    public function test_campana_de_otra_marca_no_se_modifica(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->org->id, 'brand_id' => $this->other->id, 'name' => 'Black Friday',
        ]);

        $this->actingInOrganization($this->limited, $this->org)
            ->patchJson("/api/v1/campaigns/{$campaign->public_id}", ['name' => 'Hackeada'])
            ->assertForbidden();
        $this->actingInOrganization($this->limited, $this->org)
            ->deleteJson("/api/v1/campaigns/{$campaign->public_id}")
            ->assertForbidden();

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'name' => 'Black Friday', 'deleted_at' => null]);
    }

    public function test_conversacion_de_otra_marca_no_se_lee_ni_se_responde(): void
    {
        $connection = SocialConnection::query()->create([
            'organization_id' => $this->org->id, 'brand_id' => $this->other->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo',
        ]);
        $conversation = InboxConversation::query()->create([
            'organization_id' => $this->org->id, 'brand_id' => $this->other->id, 'social_connection_id' => $connection->id,
            'provider' => 'fake', 'external_id' => 'c-1', 'type' => 'comment', 'status' => 'open', 'unread_count' => 2,
        ]);
        $as = fn () => $this->actingInOrganization($this->limited, $this->org);

        $as()->getJson("/api/v1/inbox/{$conversation->public_id}")->assertForbidden();
        $as()->postJson("/api/v1/inbox/{$conversation->public_id}/reply", ['body' => 'Hola'])->assertForbidden();
        $as()->postJson("/api/v1/inbox/{$conversation->public_id}/status", ['status' => 'resolved'])->assertForbidden();

        // No se marcó como leída ni cambió de estado.
        $this->assertDatabaseHas('inbox_conversations', ['id' => $conversation->id, 'unread_count' => 2, 'status' => 'open']);
    }

    public function test_automatizaciones_de_otra_marca_quedan_fuera(): void
    {
        $payload = fn (Brand $b) => [
            'name' => 'Aviso ' . $b->name, 'trigger' => 'content.published', 'brand' => $b->public_id,
            'actions' => [['type' => 'webhook', 'config' => ['url' => 'https://example.test/hook']]],
        ];
        $foreignId = $this->actingInOrganization($this->owner, $this->org)
            ->postJson('/api/v1/automations', $payload($this->other))
            ->assertCreated()
            ->json('data.id');
        $this->actingInOrganization($this->owner, $this->org)
            ->postJson('/api/v1/automations', [...$payload($this->allowed), 'brand' => null, 'name' => 'Global'])
            ->assertCreated();

        $as = fn () => $this->actingInOrganization($this->limited, $this->org);

        $names = collect($as()->getJson('/api/v1/automations')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Global'], $names);

        $as()->getJson("/api/v1/automations/{$foreignId}")->assertForbidden();
        $as()->deleteJson("/api/v1/automations/{$foreignId}")->assertForbidden();
        // Tampoco puede crear una automatización sobre una marca a la que no accede.
        $as()->postJson('/api/v1/automations', $payload($this->other))->assertForbidden();
        $as()->postJson('/api/v1/automations', $payload($this->allowed))->assertCreated();
    }
}
