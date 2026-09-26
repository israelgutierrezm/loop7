<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Analytics\Jobs\SyncAccountMetrics;
use App\Modules\Analytics\Services\MetricsSyncService;
use App\Modules\Api\Services\ApiKeyService;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\PublishingService;
use App\Modules\Inbox\Jobs\SyncInboxConversations;
use App\Modules\Inbox\Services\InboxService;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_suspender_corta_el_acceso_la_api_la_publicacion_y_las_sincronizaciones(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional'); // incluye la API
        $key = app(ApiKeyService::class)->generate($org, 'CRM', ['brands:read'])['plain'];
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo', 'access_token' => 'TOKEN',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'd1', 'name' => 'Página', 'type' => 'page', 'access_token' => 'PAGE', 'is_active' => true,
        ]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Programado', 'status' => 'scheduled',
        ]);
        $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => 'fake', 'body' => 'Hola']);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id, 'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id, 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $this->postJson("/api/v1/platform/organizations/{$org->public_id}/suspend", ['reason' => 'Spam'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.organization_suspended', 'organization_id' => $org->id]);

        $this->actingInOrganization($owner, $org)->getJson('/api/v1/context')
            ->assertForbidden()->assertJsonPath('code', 'organization_suspended');
        $this->withToken($key)->getJson('/api/public/v1/me')
            ->assertForbidden()->assertJsonPath('code', 'organization_suspended');

        app(PublishingService::class)->publishTarget($target->fresh(), finalAttempt: true);
        $this->assertSame('failed', $target->fresh()->status->value);
        $this->assertSame('La organización está suspendida.', $target->fresh()->error);

        Queue::fake();
        app(MetricsSyncService::class)->syncDue();
        app(InboxService::class)->syncDue();
        Queue::assertNotPushed(SyncAccountMetrics::class);
        Queue::assertNotPushed(SyncInboxConversations::class);

        // Al reactivarla todo vuelve (y queda auditado).
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $this->postJson("/api/v1/platform/organizations/{$org->public_id}/activate")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.organization_activated']);
        $this->actingInOrganization($owner->fresh(), $org)->getJson('/api/v1/context')->assertOk();
        app(InboxService::class)->syncDue();
        Queue::assertPushed(SyncInboxConversations::class);
    }

    public function test_la_ficha_muestra_miembros_con_rol_y_marcas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->addMember($org, OrganizationRole::ANALYST->value, userAttributes: ['name' => 'Ana Analista']);
        Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Marca Uno']);

        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $response = $this->getJson("/api/v1/platform/organizations/{$org->public_id}")->assertOk();

        $members = collect($response->json('data.members'))->keyBy('email');
        $this->assertTrue($members[$owner->email]['is_owner']);
        $this->assertSame('OWNER', $members[$owner->email]['role']);
        $this->assertSame('ANALYST', $members->firstWhere('name', 'Ana Analista')['role']);
        $response->assertJsonPath('data.brands.0.name', 'Marca Uno')->assertJsonPath('data.brands.0.connections_count', 0);
    }

    public function test_superadmin_elimina_una_organizacion_a_peticion(): void
    {
        [, $org] = $this->createOwnerWithOrganization([], 'Cliente Norte');
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->deleteJson("/api/v1/platform/organizations/{$org->public_id}", ['confirm_name' => 'Otro'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_name');

        Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)
            ->update(['status' => 'active', 'gateway' => 'stripe', 'gateway_subscription_id' => 'sub_1']);
        $this->deleteJson("/api/v1/platform/organizations/{$org->public_id}", ['confirm_name' => 'Cliente Norte'])
            ->assertStatus(409);

        Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->update(['cancel_at_period_end' => true]);
        $this->deleteJson("/api/v1/platform/organizations/{$org->public_id}", ['confirm_name' => 'cliente norte'])->assertOk();
        $this->assertSoftDeleted('organizations', ['id' => $org->id]);
    }

    public function test_solo_superadmin(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)
            ->deleteJson("/api/v1/platform/organizations/{$org->public_id}", ['confirm_name' => $org->name])
            ->assertForbidden();
        $this->actingInOrganization($owner, $org)->getJson("/api/v1/platform/organizations/{$org->public_id}")->assertForbidden();
    }
}
