<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\PublishingService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: Organization, 1: Brand, 2: SocialConnectionDestination}
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
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id,
            'social_connection_id' => $connection->id,
            'external_id' => 'dest-1',
            'name' => 'Página',
            'type' => 'page',
        ]);

        return [$org, $brand, $destination];
    }

    private function contentWithVariant(Organization $org, Brand $brand, string $body = 'Hola'): ContentItem
    {
        $content = ContentItem::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'title' => 'Pieza',
            'status' => 'approved',
        ]);
        $content->variants()->create([
            'organization_id' => $org->id,
            'provider' => 'fake',
            'body' => $body,
            'format' => 'text',
        ]);

        return $content;
    }

    public function test_publicar_ahora_publica_todos_los_targets(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $owner = $org->owner;
        $content = $this->contentWithVariant($org, $brand);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertOk();

        $this->assertDatabaseHas('publication_targets', ['status' => 'published']);
        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'status' => 'published']);

        $target = PublicationTarget::query()->withoutGlobalScopes()->first();
        $this->assertNotNull($target->remote_id);
    }

    public function test_content_creator_no_puede_publicar_ahora(): void
    {
        [$org, $brand] = $this->connectedBrand();
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $content = $this->contentWithVariant($org, $brand);

        $this->actingInOrganization($creator, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertForbidden();
    }

    public function test_fallo_de_una_red_produce_partial(): void
    {
        [$org, $brand, $destination] = $this->connectedBrand();
        $content = $this->contentWithVariant($org, $brand, 'Ok');
        // Segunda variante que fallará al publicar.
        $content->variants()->create([
            'organization_id' => $org->id,
            'provider' => 'fake',
            'body' => 'Esto va a fallar [[FAIL]]',
            'format' => 'text',
        ]);

        $publishing = app(PublishingService::class);
        $variants = PostVariant::query()->withoutGlobalScopes()->where('content_item_id', $content->id)->get();
        foreach ($variants as $variant) {
            $target = PublicationTarget::query()->create([
                'organization_id' => $org->id,
                'post_variant_id' => $variant->id,
                'social_connection_destination_id' => $destination->id,
                'status' => TargetStatus::SCHEDULED->value,
            ]);
            try {
                $publishing->publishTarget($target);
            } catch (Throwable) {
                // el target que falla lanza excepción (reintentable); lo ignoramos aquí
            }
        }

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'status' => 'partial']);
    }

    public function test_publicar_es_idempotente(): void
    {
        [$org, $brand, $destination] = $this->connectedBrand();
        $content = $this->contentWithVariant($org, $brand);
        $variant = PostVariant::query()->withoutGlobalScopes()->where('content_item_id', $content->id)->first();

        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id,
            'status' => TargetStatus::SCHEDULED->value,
        ]);

        $publishing = app(PublishingService::class);
        $publishing->publishTarget($target);
        $remoteId = $target->fresh()->remote_id;

        // Segundo intento: no republica (idempotente) → mismo remote_id, un solo intento.
        $publishing->publishTarget($target->fresh());

        $this->assertSame($remoteId, $target->fresh()->remote_id);
        $this->assertSame(1, DB::table('publication_attempts')->where('publication_target_id', $target->id)->count());
    }

    public function test_token_caducado_marca_la_conexion_como_expirada(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400)]);
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'facebook',
            'status' => 'connected', 'external_account_name' => 'Meta', 'access_token' => 'USER_TOKEN',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'PAGE1', 'name' => 'Página', 'type' => 'page', 'access_token' => 'PAGE_TOKEN',
        ]);
        $content = $this->contentWithVariant($org, $brand);
        $variant = $content->variants()->firstOrFail();
        $variant->update(['provider' => 'facebook']);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id, 'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id, 'status' => TargetStatus::SCHEDULED->value,
        ]);

        // No lanza: reintentar no sirve, hay que reconectar.
        app(PublishingService::class)->publishTarget($target);

        $this->assertSame(TargetStatus::FAILED, $target->fresh()->status);
        $this->assertStringContainsString('Reconecta', (string) $target->fresh()->error);
        $this->assertSame('expired', $connection->fresh()->status->value);
        $this->assertDatabaseHas('social_token_events', ['social_connection_id' => $connection->id, 'event' => 'expired']);
    }

    public function test_instagram_sin_imagen_no_se_puede_publicar(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $owner = $org->owner;
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'instagram',
            'status' => 'connected', 'external_account_name' => 'IG', 'access_token' => 'T',
        ]);
        SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'IG1', 'name' => '@marca', 'type' => 'instagram_business',
        ]);
        $content = $this->contentWithVariant($org, $brand);
        $content->variants()->firstOrFail()->update(['provider' => 'instagram']);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertStatus(422)
            ->assertJsonFragment(['Instagram exige al menos una imagen o un video.']);

        $this->assertDatabaseCount('publication_targets', 0);
    }

    public function test_sin_cuentas_conectadas_no_se_puede_programar(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $owner = $org->owner;
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = $this->contentWithVariant($org, $brand);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422);

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'status' => 'approved']);
    }

    public function test_el_scheduler_despacha_targets_vencidos(): void
    {
        [$org, $brand, $destination] = $this->connectedBrand();
        $content = $this->contentWithVariant($org, $brand);
        $content->update(['status' => 'scheduled']);
        $variant = PostVariant::query()->withoutGlobalScopes()->where('content_item_id', $content->id)->first();

        PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id,
            'status' => TargetStatus::SCHEDULED->value,
            'scheduled_at' => now()->subMinute(),
        ]);

        $count = app(PublishingService::class)->dispatchDue();

        $this->assertSame(1, $count);
        // La cola sync ejecuta el job en el acto → target publicado.
        $this->assertDatabaseHas('publication_targets', ['status' => 'published']);
    }
}
