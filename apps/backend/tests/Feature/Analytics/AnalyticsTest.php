<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Analytics\Services\MetricsSyncService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: Organization, 1: Brand, 2: SocialConnectionDestination, 3: PublicationTarget}
     */
    private function connectedBrandWithPost(string $provider = 'fake'): array
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'provider' => $provider,
            'status' => 'connected',
            'external_account_name' => 'Demo',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id,
            'social_connection_id' => $connection->id,
            'external_id' => 'dest-1',
            'name' => 'Página',
            'type' => 'page',
        ]);

        $content = ContentItem::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'title' => 'Pieza publicada',
            'status' => 'published',
        ]);
        $variant = $content->variants()->create([
            'organization_id' => $org->id,
            'provider' => $provider,
            'body' => 'Hola',
            'format' => 'text',
        ]);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id,
            'status' => TargetStatus::PUBLISHED->value,
            'remote_id' => 'fake-post-1',
            'published_at' => now(),
        ]);

        return [$org, $brand, $destination, $target];
    }

    public function test_sincroniza_metricas_de_cuenta_y_post(): void
    {
        [$org, $brand] = $this->connectedBrandWithPost();

        $counts = app(MetricsSyncService::class)->syncBrand($brand);

        $this->assertSame(1, $counts['accounts']);
        $this->assertSame(1, $counts['posts']);
        $this->assertDatabaseHas('account_metric_snapshots', ['brand_id' => $brand->id, 'provider' => 'fake']);
        $this->assertDatabaseHas('post_metric_snapshots', ['brand_id' => $brand->id, 'remote_id' => 'fake-post-1']);
    }

    public function test_overview_devuelve_kpis_series_y_top_posts(): void
    {
        [$org, $brand] = $this->connectedBrandWithPost();
        app(MetricsSyncService::class)->backfillDemo($brand, 10);
        $owner = $org->owner;

        $response = $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/analytics/overview")
            ->assertOk();

        $this->assertGreaterThan(0, $response->json('data.kpis.followers'));
        $this->assertGreaterThan(0, $response->json('data.kpis.impressions'));
        $this->assertNotEmpty($response->json('data.series'));
        $this->assertNotEmpty($response->json('data.by_channel'));
        $this->assertNotEmpty($response->json('data.top_posts'));
    }

    public function test_sync_endpoint_actualiza_metricas(): void
    {
        [$org, $brand] = $this->connectedBrandWithPost();
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/analytics/sync")
            ->assertOk()
            ->assertJsonPath('data.accounts', 1)
            ->assertJsonPath('data.posts', 1);
    }

    public function test_rol_sin_permiso_no_ve_analitica(): void
    {
        [$org, $brand] = $this->connectedBrandWithPost();
        $billing = $this->addMember($org, OrganizationRole::BILLING->value);

        $this->actingInOrganization($billing, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/analytics/overview")
            ->assertForbidden();
    }

    public function test_no_puede_ver_analitica_de_otra_organizacion(): void
    {
        [$orgA] = $this->connectedBrandWithPost();
        [, $brandB] = $this->connectedBrandWithPost();
        $ownerA = $orgA->owner;

        $this->actingInOrganization($ownerA, $orgA)
            ->getJson("/api/v1/brands/{$brandB->public_id}/analytics/overview")
            ->assertNotFound();
    }

    public function test_exportar_requiere_plan_avanzado(): void
    {
        [$org, $brand] = $this->connectedBrandWithPost();
        $this->setOrganizationPlan($org, 'starter'); // sin analítica avanzada
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/analytics/export")
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.analytics_advanced');
    }

    public function test_exportar_genera_csv_en_plan_avanzado(): void
    {
        [$org, $brand] = $this->connectedBrandWithPost();
        $this->setOrganizationPlan($org, 'professional'); // con analítica avanzada
        app(MetricsSyncService::class)->syncBrand($brand);
        $owner = $org->owner;

        $response = $this->actingInOrganization($owner, $org)
            ->get("/api/v1/brands/{$brand->public_id}/analytics/export")
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('Impresiones', $response->streamedContent());
    }

    public function test_proveedor_no_configurado_se_omite(): void
    {
        // Facebook sin credenciales: la sincronización se omite sin crear snapshots.
        [, $brand] = $this->connectedBrandWithPost('facebook');

        $counts = app(MetricsSyncService::class)->syncBrand($brand);

        $this->assertSame(0, $counts['accounts']);
        $this->assertSame(0, $counts['posts']);
        $this->assertDatabaseMissing('account_metric_snapshots', ['brand_id' => $brand->id]);
    }
}
