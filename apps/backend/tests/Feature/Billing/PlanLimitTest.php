<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Billing\Models\OrganizationEntitlementOverride;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Criterio docs/15: el plan limita brands y miembros.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_el_plan_limita_el_numero_de_marcas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // brands.max = 1

        // Primera marca: OK.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/brands', ['name' => 'Marca 1'])
            ->assertCreated();

        // Segunda marca: bloqueada por el plan (HTTP 402).
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/brands', ['name' => 'Marca 2'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_el_plan_limita_el_numero_de_miembros(): void
    {
        Notification::fake();
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // team_members.max = 2 (owner + 1)

        // Primera invitación: OK (owner=1 + 1 invitación = 2).
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/invitations', [
                'email' => 'uno@example.com',
                'role' => OrganizationRole::VIEWER->value,
            ])
            ->assertCreated();

        // Segunda invitación: excede el plan.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/invitations', [
                'email' => 'dos@example.com',
                'role' => OrganizationRole::VIEWER->value,
            ])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_el_plan_limita_las_cuentas_sociales(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // social_accounts.max = 3
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        for ($i = 1; $i <= 3; $i++) {
            $this->actingInOrganization($owner, $org)
                ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/manual", [
                    'external_account_name' => "Cuenta {$i}", 'external_account_id' => "acc-{$i}", 'access_token' => 't',
                    'destinations' => [['external_id' => "d{$i}", 'name' => "Destino {$i}"]],
                ])->assertCreated();
        }

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/manual", [
                'external_account_name' => 'Cuenta 4', 'external_account_id' => 'acc-4', 'access_token' => 't',
            ])
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'social_accounts.max');

        // Reconectar una cuenta existente no cuenta como nueva.
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/manual", [
                'external_account_name' => 'Cuenta 1', 'external_account_id' => 'acc-1', 'access_token' => 'nuevo',
            ])->assertCreated();
    }

    public function test_el_plan_limita_las_publicaciones_del_mes(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter');
        OrganizationEntitlementOverride::query()->create([
            'organization_id' => $org->id, 'entitlement_key' => 'scheduled_posts.month', 'value' => '1',
        ]);
        app(EntitlementsService::class)->flush();

        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo',
        ]);
        SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'dest-1', 'name' => 'Página', 'type' => 'page',
        ]);

        $makeContent = function () use ($org, $brand): ContentItem {
            $content = ContentItem::query()->create([
                'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Pieza', 'status' => 'approved',
            ]);
            $content->variants()->create(['organization_id' => $org->id, 'provider' => 'fake', 'body' => 'Hola', 'format' => 'text']);

            return $content;
        };

        $first = $makeContent();
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$first->public_id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertOk();

        // Reprogramar la misma pieza no suma una publicación nueva.
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$first->public_id}/schedule", ['scheduled_at' => now()->addDays(2)->toIso8601String()])
            ->assertOk();

        $second = $makeContent();
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$second->public_id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'scheduled_posts.month');
    }

    public function test_sin_flujos_de_aprobacion_se_aprueba_directamente(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // feature.approvals = false
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Borrador', 'status' => 'draft',
        ]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/submit")
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.approvals');

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'status' => 'approved']);
    }
}
