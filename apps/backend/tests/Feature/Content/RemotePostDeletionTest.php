<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Borrar de la red una publicación hecha desde Loop7 (docs/05): estados,
 * idempotencia, redes sin borrado, token caducado, permisos, aislamiento,
 * auditoría y webhook.
 */
class RemotePostDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = 'https://93.184.216.34/hooks/loop7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * Contenido publicado en `$accounts` cuentas de la red indicada.
     *
     * @param  list<string>  $remoteIds
     * @return array{0: Organization, 1: User, 2: ContentItem, 3: list<PublicationTarget>}
     */
    private function published(string $provider = 'fake', array $remoteIds = ['post-1']): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'provider' => $provider,
            'status' => 'connected',
            'external_account_name' => 'Cuenta',
            'access_token' => 'ACCESS',
        ]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'title' => 'Lanzamiento',
            'status' => 'published',
        ]);
        $variant = $content->variants()->create([
            'organization_id' => $org->id,
            'provider' => $provider,
            'body' => 'Hola',
            'format' => 'text',
        ]);

        $targets = [];
        foreach ($remoteIds as $i => $remoteId) {
            $destination = SocialConnectionDestination::query()->create([
                'organization_id' => $org->id,
                'social_connection_id' => $connection->id,
                'external_id' => "dest-{$i}",
                'name' => "Cuenta {$i}",
                'type' => 'page',
            ]);
            $targets[] = PublicationTarget::query()->create([
                'organization_id' => $org->id,
                'post_variant_id' => $variant->id,
                'social_connection_destination_id' => $destination->id,
                'status' => TargetStatus::PUBLISHED->value,
                'remote_id' => $remoteId,
                'remote_url' => "https://fake.social/{$remoteId}",
                'published_at' => now()->subDay(),
            ]);
        }

        return [$org, $owner, $content, $targets];
    }

    private function url(PublicationTarget $target): string
    {
        return "/api/v1/publication-targets/{$target->public_id}/remote";
    }

    public function test_borra_de_la_red_y_retira_el_contenido_si_ya_no_queda_nada(): void
    {
        [$org, $owner, $content, [$target]] = $this->published();

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.status_label', 'Borrado de la red')
            ->assertJsonPath('data.content_status', 'unpublished')
            ->assertJsonPath('data.content_status_label', 'Retirado');

        $target->refresh();
        $this->assertSame(TargetStatus::DELETED, $target->status);
        $this->assertNotNull($target->remote_deleted_at);
        $this->assertSame('post-1', $target->remote_id); // se conserva para la auditoría
        $this->assertDatabaseHas('audit_logs', ['action' => 'publication.remote_deleted', 'organization_id' => $org->id]);

        // En el detalle ya no se ofrece borrarla.
        $this->actingInOrganization($owner, $org)->getJson("/api/v1/content/{$content->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'unpublished')
            ->assertJsonPath('data.variants.0.targets.0.status', 'deleted')
            ->assertJsonPath('data.variants.0.targets.0.can_delete_remote', false);
    }

    public function test_si_sigue_en_otra_cuenta_el_contenido_sigue_publicado(): void
    {
        [$org, $owner, $content, [$first, $second]] = $this->published(remoteIds: ['post-1', 'post-2']);

        $this->actingInOrganization($owner, $org)->getJson("/api/v1/content/{$content->public_id}")
            ->assertJsonPath('data.variants.0.targets.0.can_delete_remote', true);

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($first))
            ->assertOk()
            ->assertJsonPath('data.content_status', 'published');

        $this->assertSame(TargetStatus::PUBLISHED, $second->fresh()->status);
    }

    public function test_es_idempotente(): void
    {
        [$org, $owner, , [$target]] = $this->published();

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))->assertOk();
        $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted');

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'publication.remote_deleted')->count());
    }

    public function test_una_red_sin_borrado_por_api_lo_explica(): void
    {
        [$org, $owner, $content, [$target]] = $this->published('instagram');

        $this->actingInOrganization($owner, $org)->getJson("/api/v1/content/{$content->public_id}")
            ->assertJsonPath('data.variants.0.targets.0.can_delete_remote', false);

        $response = $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target');
        $this->assertStringContainsString('no permite borrar', (string) $response->json('errors.target.0'));
        $this->assertSame(TargetStatus::PUBLISHED, $target->fresh()->status);
    }

    public function test_solo_se_borra_lo_publicado(): void
    {
        [$org, $owner, , [$target]] = $this->published();
        $target->update(['status' => TargetStatus::SCHEDULED->value]);

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))
            ->assertUnprocessable()
            ->assertJsonPath('errors.target.0', 'Sólo se puede borrar de la red algo ya publicado.');
    }

    public function test_si_la_red_lo_rechaza_no_cambia_nada(): void
    {
        [$org, $owner, $content, [$target]] = $this->published(remoteIds: ['post-[[FAIL]]']);

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))
            ->assertUnprocessable()
            ->assertJsonPath('errors.target.0', 'El proveedor de prueba no permitió borrar la publicación.');

        $this->assertSame(TargetStatus::PUBLISHED, $target->fresh()->status);
        $this->assertSame('published', $content->fresh()->status->value);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'publication.remote_deleted']);
    }

    public function test_token_caducado_pide_reconectar_y_marca_la_conexion(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.x.com/*' => Http::response(['title' => 'Unauthorized'], 401)]);
        [$org, $owner, , [$target]] = $this->published('x', ['1790000000000000001']);

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target');

        $this->assertDatabaseHas('social_connections', ['organization_id' => $org->id, 'provider' => 'x', 'status' => 'expired']);
        $this->assertSame(TargetStatus::PUBLISHED, $target->fresh()->status);
    }

    public function test_requiere_permiso_de_borrar_y_acceso_a_la_marca(): void
    {
        [$org, , , [$target]] = $this->published();

        // PUBLISHER publica pero no borra contenido.
        $publisher = $this->addMember($org, OrganizationRole::PUBLISHER->value);
        $this->actingInOrganization($publisher, $org)->deleteJson($this->url($target))->assertForbidden();

        $restricted = $this->addMember($org, OrganizationRole::MANAGER->value, allBrandsAccess: false);
        $this->actingInOrganization($restricted, $org)->deleteJson($this->url($target))->assertForbidden();

        $this->assertSame(TargetStatus::PUBLISHED, $target->fresh()->status);
    }

    public function test_no_alcanza_publicaciones_de_otra_organizacion(): void
    {
        [$orgA, $ownerA] = $this->published();
        [, , , [$targetB]] = $this->published();

        $this->actingInOrganization($ownerA, $orgA)->deleteJson($this->url($targetB))->assertNotFound();
        $this->assertSame(TargetStatus::PUBLISHED, $targetB->fresh()->status);
    }

    public function test_avisa_por_webhook(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('ok', 200)]);
        [$org, $owner, $content, [$target]] = $this->published();
        $this->setOrganizationPlan($org, 'professional'); // webhooks incluidos
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/webhooks', ['url' => self::WEBHOOK_URL, 'events' => ['publication.deleted']])
            ->assertCreated();

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($target))->assertOk();

        Http::assertSent(function (Request $request) use ($content, $target): bool {
            $payload = json_decode($request->body(), true);

            return $request->url() === self::WEBHOOK_URL
                && $payload['type'] === 'publication.deleted'
                && $payload['data']['content']['id'] === $content->public_id
                && $payload['data']['content']['status'] === 'unpublished'
                && $payload['data']['publication']['id'] === $target->public_id
                && $payload['data']['publication']['provider'] === 'fake'
                && $payload['data']['publication']['deleted_at'] !== null;
        });
    }
}
