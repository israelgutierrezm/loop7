<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\User;
use App\Modules\Billing\Models\OrganizationEntitlementOverride;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\PublishingService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cupos por red del plan (docs/08): X cobra cada publicación a la plataforma y
 * YouTube comparte 100 subidas al día entre todos los clientes.
 */
class ProviderQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Http::preventStrayRequests();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Brand, 3: SocialConnectionDestination}
     */
    private function connected(string $provider, string $entitlement, int $limit): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        OrganizationEntitlementOverride::query()->create([
            'organization_id' => $org->id, 'entitlement_key' => $entitlement, 'value' => (string) $limit,
        ]);
        app(EntitlementsService::class)->flush();

        $record = SocialProvider::query()->where('key', $provider)->firstOrFail();
        $record->is_enabled = true;
        $record->credentials = ['client_id' => 'CID', 'client_secret' => 'SECRET'];
        $record->save();

        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => $provider,
            'status' => 'connected', 'external_account_name' => 'Cuenta', 'access_token' => 'ACCESS',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'U1', 'name' => '@cuenta', 'type' => 'profile',
        ]);

        return [$owner, $org, $brand, $destination];
    }

    private function content(Organization $org, Brand $brand, string $provider, string $status = 'approved'): ContentItem
    {
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Pieza', 'status' => $status,
        ]);
        $content->variants()->create(['organization_id' => $org->id, 'provider' => $provider, 'body' => 'Hola', 'format' => 'text']);

        return $content;
    }

    private function publishedTarget(Organization $org, Brand $brand, SocialConnectionDestination $destination, string $provider, \DateTimeInterface $at): void
    {
        $content = $this->content($org, $brand, $provider, 'published');
        PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $content->variants()->firstOrFail()->id,
            'social_connection_destination_id' => $destination->id,
            'status' => 'published',
            'remote_id' => 'R' . random_int(1, 999999),
            'published_at' => $at,
        ]);
    }

    public function test_programar_en_x_respeta_el_cupo_mensual_del_mes_elegido(): void
    {
        // A mitad de mes: en su última hora, «fin de mes menos una hora» ya habría pasado.
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        [$owner, $org, $brand, $destination] = $this->connected('x', 'x_posts.month', 2);
        $this->publishedTarget($org, $brand, $destination, 'x', now()->startOfMonth()->addHour());
        $this->publishedTarget($org, $brand, $destination, 'x', now()->startOfMonth()->addHours(2));

        // Este mes ya se agotó el cupo.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/content/' . $this->content($org, $brand, 'x')->public_id . '/schedule', [
                'scheduled_at' => now()->endOfMonth()->subHour()->toIso8601String(),
            ])
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'x_posts.month');

        // El mes siguiente tiene su propio cupo.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/content/' . $this->content($org, $brand, 'x')->public_id . '/schedule', [
                'scheduled_at' => now()->addMonthNoOverflow()->startOfMonth()->addDays(2)->toIso8601String(),
            ])
            ->assertOk();
    }

    public function test_al_publicar_sin_cupo_la_publicacion_falla_sin_llamar_a_la_red(): void
    {
        // A mediodía: justo después de medianoche, «hace 30 minutos» sería otro día.
        $this->travelTo(now()->setTime(12, 0));
        [, $org, $brand, $destination] = $this->connected('youtube', 'youtube_uploads.day', 1);
        $this->publishedTarget($org, $brand, $destination, 'youtube', now()->subMinutes(30));
        $content = $this->content($org, $brand, 'youtube');
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $content->variants()->firstOrFail()->id,
            'social_connection_destination_id' => $destination->id,
            'status' => 'scheduled',
        ]);

        app(PublishingService::class)->publishTarget($target);

        $target->refresh();
        $this->assertSame('failed', $target->status->value);
        $this->assertStringContainsString('1 subidas a YouTube al día', (string) $target->error);
        Http::assertNothingSent();
    }

    public function test_el_uso_del_cupo_se_ve_en_facturacion(): void
    {
        [$owner, $org, $brand, $destination] = $this->connected('x', 'x_posts.month', 50);
        $this->publishedTarget($org, $brand, $destination, 'x', now());
        $this->publishedTarget($org, $brand, $destination, 'x', now()->subMonthNoOverflow()); // otro mes: no cuenta

        $data = $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/billing/subscription')
            ->assertOk()
            ->json('data');

        // Las claves llevan punto: se leen del array, no con rutas de assertJsonPath.
        $this->assertSame(1, $data['usage']['x_posts.month']);
        $this->assertSame(50, $data['entitlements']['x_posts.month']);
    }
}
