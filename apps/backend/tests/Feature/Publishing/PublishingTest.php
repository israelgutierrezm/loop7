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
