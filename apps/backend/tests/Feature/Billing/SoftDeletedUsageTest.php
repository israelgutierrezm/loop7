<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Services\UsageService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\PublishingService;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo eliminado (soft delete) no ocupa cupo del plan ni se sigue publicando.
 */
class SoftDeletedUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_desconectar_libera_cupo_y_reconectar_restaura_la_cuenta(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // 3 cuentas sociales
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connect = fn (string $id) => $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/manual", [
                'external_account_name' => "Cuenta {$id}", 'external_account_id' => $id, 'access_token' => "token-{$id}",
            ]);

        $first = $connect('a')->assertCreated()->json('data.id');
        $connect('b')->assertCreated();
        $connect('c')->assertCreated();
        $connect('d')->assertStatus(402); // límite alcanzado

        // Desconectar libera el cupo…
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/social/connections/{$first}")->assertOk();
        $this->assertSame(2, app(UsageService::class)->socialAccounts($org));
        $connect('d')->assertCreated();
        $connect('e')->assertStatus(402);

        // …y reconectar una cuenta desconectada la restaura (visible) si hay cupo.
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/social/connections/{$first}")->assertNotFound();
        $second = collect($this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/social/connections")->json('data'))
            ->firstWhere('account_name', 'Cuenta b')['id'];
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/social/connections/{$second}")->assertOk();

        $connect('b')->assertCreated();
        $names = collect($this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/social/connections")->json('data'))
            ->pluck('account_name')->sort()->values()->all();
        $this->assertSame(['Cuenta b', 'Cuenta c', 'Cuenta d'], $names);
        $this->assertDatabaseCount('social_connections', 4); // "b" restaurada, no duplicada
    }

    public function test_el_uso_no_cuenta_marcas_ni_archivos_eliminados(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $gone = Brand::factory()->create(['organization_id' => $org->id]);
        $asset = fn (int $bytes) => MediaAsset::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'disk' => 'local', 'path' => 'x/' . uniqid(),
            'original_name' => 'f.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => $bytes,
        ]);
        $asset(1024 ** 3);
        $asset(1024 ** 3)->delete();
        $gone->delete();

        $usage = app(UsageService::class)->current($org);

        $this->assertSame(1, $usage['brands.max']);
        $this->assertEquals(1.0, $usage['storage.gb']);
    }

    public function test_el_contenido_eliminado_no_se_publica(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = \App\Modules\SocialConnections\Models\SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'd-1', 'name' => 'Página', 'type' => 'page',
        ]);
        $target = function (string $title) use ($org, $brand, $destination): PublicationTarget {
            $content = ContentItem::query()->create([
                'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => $title,
                'status' => 'scheduled', 'scheduled_at' => now()->addHour(),
            ]);
            $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => 'fake', 'body' => 'Hola']);

            return PublicationTarget::query()->create([
                'organization_id' => $org->id, 'post_variant_id' => $variant->id,
                'social_connection_destination_id' => $destination->id, 'status' => 'scheduled', 'scheduled_at' => now()->addHour(),
            ]);
        };

        // Eliminarlo cancela lo programado.
        $scheduled = $target('Programado');
        $contentId = ContentItem::query()->where('title', 'Programado')->value('public_id');
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/content/{$contentId}")->assertOk();
        $this->assertSame('cancelled', $scheduled->fresh()->status->value);

        // Si el job ya estaba en cola, el motor tampoco publica.
        $queued = $target('En cola');
        ContentItem::query()->where('title', 'En cola')->firstOrFail()->delete();
        app(PublishingService::class)->publishTarget($queued->fresh());
        $this->assertSame('cancelled', $queued->fresh()->status->value);
        $this->assertNull($queued->fresh()->remote_id);
    }
}
