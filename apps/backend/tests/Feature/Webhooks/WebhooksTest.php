<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Content\Events\ContentReviewed;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookDeliverer;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://93.184.216.34/hooks/loop7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function proOrg(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional'); // feature.api = true

        return [$owner, $org];
    }

    /**
     * @param  list<string>  $events
     * @return array{0: WebhookEndpoint, 1: string}  [endpoint, secreto]
     */
    private function createEndpoint(User $owner, Organization $org, array $events = ['content.published']): array
    {
        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/webhooks', ['url' => self::URL, 'description' => 'CRM', 'events' => $events])
            ->assertCreated();

        $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->where('public_id', $response->json('data.id'))->firstOrFail();

        return [$endpoint, (string) $response->json('data.secret')];
    }

    private function publishedContent(Organization $org): ContentItem
    {
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Norte']);

        return ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Lanzamiento', 'status' => 'published',
        ]);
    }

    public function test_crear_endpoint_muestra_el_secreto_una_vez_y_lo_guarda_cifrado(): void
    {
        [$owner, $org] = $this->proOrg();

        [$endpoint, $secret] = $this->createEndpoint($owner, $org);

        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $endpoint->secret);
        // En la BD está cifrado, nunca en claro.
        $raw = (string) DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');
        $this->assertStringNotContainsString($secret, $raw);

        $list = $this->actingInOrganization($owner, $org)->getJson('/api/v1/webhooks')->assertOk();
        $list->assertJsonPath('data.endpoints.0.secret_hint', 'whsec_••••' . substr($secret, -4));
        $this->assertStringNotContainsString($secret, (string) $list->getContent());
        $this->assertNotEmpty($list->json('data.events'));
    }

    public function test_evento_de_dominio_envia_post_firmado_segun_standard_webhooks(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$owner, $org] = $this->proOrg();
        [$endpoint, $secret] = $this->createEndpoint($owner, $org);
        $content = $this->publishedContent($org);

        event(new ContentPublished($content, 'published'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($secret, $content): bool {
            $body = $request->body();
            $payload = json_decode($body, true);

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && str_starts_with($request->header('webhook-id')[0] ?? '', 'msg_')
                && app(WebhookSigner::class)->verify(
                    $secret,
                    $request->header('webhook-id')[0],
                    (int) $request->header('webhook-timestamp')[0],
                    $body,
                    $request->header('webhook-signature')[0],
                )
                && $payload['type'] === 'content.published'
                && $payload['data']['content']['id'] === $content->public_id
                && $payload['data']['content']['brand']['name'] === 'Café Norte'
                && ! array_key_exists('organization_id', $payload['data']['content']);
        });

        $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('succeeded', $delivery->status->value);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->response_status);
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures);
    }

    public function test_solo_llega_a_endpoints_suscritos_y_activos_de_la_misma_organizacion(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$owner, $org] = $this->proOrg();
        $this->createEndpoint($owner, $org, ['content.approved']); // otro evento
        [$paused] = $this->createEndpoint($owner, $org);
        $paused->update(['is_active' => false]);
        [$otherOwner, $otherOrg] = $this->proOrg();
        $this->createEndpoint($otherOwner, $otherOrg); // otra organización

        event(new ContentPublished($this->publishedContent($org), 'published'));

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::query()->withoutGlobalScopes()->count());
    }

    public function test_aprobacion_y_cambios_pedidos_son_eventos_distintos(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$owner, $org] = $this->proOrg();
        $this->createEndpoint($owner, $org, ['content.changes_requested']);
        $content = $this->publishedContent($org);

        event(new ContentReviewed($content, $owner, true));
        Http::assertNothingSent();

        event(new ContentReviewed($content, $owner, false, 'Cambia la foto'));
        Http::assertSent(fn (Request $r) => $r['type'] === 'content.changes_requested'
            && $r['data']['note'] === 'Cambia la foto'
            && $r['data']['reviewer']['id'] === $owner->public_id);
    }

    public function test_fallo_reintenta_y_al_agotar_intentos_queda_fallida(): void
    {
        Http::fake(['*' => Http::response('caído', 500)]);
        [$owner, $org] = $this->proOrg();
        [$endpoint] = $this->createEndpoint($owner, $org);

        event(new ContentPublished($this->publishedContent($org), 'published'));

        $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('pending', $delivery->status->value);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertSame('El endpoint respondió 500.', $delivery->error);

        // El worker reprograma los reintentos; aquí se simulan hasta agotarlos.
        $deliverer = app(WebhookDeliverer::class);
        for ($i = 2; $i <= WebhookDeliverer::MAX_ATTEMPTS; $i++) {
            $deliverer->deliver($delivery->id);
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status->value);
        $this->assertSame(WebhookDeliverer::MAX_ATTEMPTS, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(1, $endpoint->fresh()->consecutive_failures);

        // Idempotente: una entrega resuelta no se vuelve a enviar.
        $sent = count(Http::recorded());
        $this->assertNull($deliverer->deliver($delivery->id));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_redireccion_no_se_sigue_y_cuenta_como_fallo(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);
        [$owner, $org] = $this->proOrg();
        $this->createEndpoint($owner, $org);

        event(new ContentPublished($this->publishedContent($org), 'published'));

        Http::assertSentCount(1);
        $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertStringContainsString('redirección', (string) $delivery->error);
    }

    public function test_endpoint_se_desactiva_tras_fallos_seguidos_y_avisa(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        [$owner, $org] = $this->proOrg();
        [$endpoint] = $this->createEndpoint($owner, $org);
        $endpoint->update(['consecutive_failures' => WebhookDeliverer::DISABLE_AFTER_FAILURES - 1]);

        event(new ContentPublished($this->publishedContent($org), 'published'));
        $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();
        $deliverer = app(WebhookDeliverer::class);
        for ($i = 2; $i <= WebhookDeliverer::MAX_ATTEMPTS; $i++) {
            $deliverer->deliver($delivery->id);
        }

        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertNotNull($endpoint->disabled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.endpoint_disabled', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id, 'organization_id' => $org->id]);

        // Reactivarlo olvida los fallos acumulados.
        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/webhooks/{$endpoint->public_id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.consecutive_failures', 0);
    }

    public function test_probar_envia_al_momento_sin_contar_como_fallo(): void
    {
        Http::fake(['*' => Http::response('nope', 404)]);
        [$owner, $org] = $this->proOrg();
        [$endpoint] = $this->createEndpoint($owner, $org);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/webhooks/{$endpoint->public_id}/test")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.event', 'webhook.test')
            ->assertJsonPath('data.response_status', 404);

        Http::assertSent(fn (Request $r) => $r['type'] === 'webhook.test');
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures);
    }

    public function test_rotar_secreto_firma_con_ambos_durante_la_transicion(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$owner, $org] = $this->proOrg();
        [$endpoint, $old] = $this->createEndpoint($owner, $org);

        $new = (string) $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/webhooks/{$endpoint->public_id}/rotate-secret")
            ->assertOk()
            ->json('data.secret');
        $this->assertNotSame($old, $new);

        event(new ContentPublished($this->publishedContent($org), 'published'));

        $signer = app(WebhookSigner::class);
        Http::assertSent(function (Request $r) use ($signer, $old, $new): bool {
            $args = [$r->header('webhook-id')[0], (int) $r->header('webhook-timestamp')[0], $r->body(), $r->header('webhook-signature')[0]];

            return $signer->verify($new, ...$args) && $signer->verify($old, ...$args);
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.secret_rotated', 'organization_id' => $org->id]);
    }

    public function test_reenviar_crea_una_entrega_nueva_con_el_mismo_mensaje(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 500)->push('ok', 200)]);
        [$owner, $org] = $this->proOrg();
        [$endpoint] = $this->createEndpoint($owner, $org);
        event(new ContentPublished($this->publishedContent($org), 'published'));
        $original = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();
        $original->update(['status' => 'failed']);

        $response = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/webhooks/{$endpoint->public_id}/deliveries/{$original->public_id}/redeliver")
            ->assertStatus(202);

        $copy = WebhookDelivery::query()->withoutGlobalScopes()->where('public_id', $response->json('data.id'))->firstOrFail();
        $this->assertSame($original->message_id, $copy->message_id);
        $this->assertSame('succeeded', $copy->status->value);

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/webhooks/{$endpoint->public_id}/deliveries")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_url_debe_ser_https_y_publica(): void
    {
        [$owner, $org] = $this->proOrg();

        foreach (['http://93.184.216.34/hook', 'https://127.0.0.1/hook', 'https://10.0.0.5/hook', 'https://localhost/hook'] as $url) {
            $this->actingInOrganization($owner, $org)
                ->postJson('/api/v1/webhooks', ['url' => $url, 'events' => ['content.published']])
                ->assertStatus(422)
                ->assertJsonValidationErrors('url');
        }

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => ['webhook.test']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('events.0');
    }

    public function test_limite_de_endpoints_por_organizacion(): void
    {
        [$owner, $org] = $this->proOrg();
        for ($i = 0; $i < 10; $i++) {
            WebhookEndpoint::query()->create([
                'organization_id' => $org->id, 'url' => self::URL, 'events' => ['content.published'], 'secret' => WebhookSigner::generateSecret(),
            ]);
        }

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => ['content.published']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_aislamiento_entre_organizaciones(): void
    {
        [$owner, $org] = $this->proOrg();
        [$endpoint] = $this->createEndpoint($owner, $org);
        [$intruder, $otherOrg] = $this->proOrg();

        $this->actingInOrganization($intruder, $otherOrg)->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(0, 'data.endpoints');
        $this->actingInOrganization($intruder, $otherOrg)->patchJson("/api/v1/webhooks/{$endpoint->public_id}", ['is_active' => false])->assertNotFound();
        $this->actingInOrganization($intruder, $otherOrg)->deleteJson("/api/v1/webhooks/{$endpoint->public_id}")->assertNotFound();
        $this->actingInOrganization($intruder, $otherOrg)->getJson("/api/v1/webhooks/{$endpoint->public_id}/deliveries")->assertNotFound();
        $this->assertTrue($endpoint->fresh()->is_active);
    }

    public function test_permiso_y_plan(): void
    {
        [, $org] = $this->proOrg();
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);
        $this->actingInOrganization($manager, $org)->getJson('/api/v1/webhooks')->assertForbidden();

        [$owner, $growth] = $this->createOwnerWithOrganization(); // prueba = Growth, sin API
        $this->actingInOrganization($owner, $growth)
            ->postJson('/api/v1/webhooks', ['url' => 'no-es-url'])
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.api');
    }

    public function test_eliminar_endpoint_audita_solo_el_dominio(): void
    {
        [$owner, $org] = $this->proOrg();
        [$endpoint] = $this->createEndpoint($owner, $org);

        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/webhooks/{$endpoint->public_id}")->assertOk();

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
        $log = DB::table('audit_logs')->where('action', 'webhook.endpoint_deleted')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('93.184.216.34', (string) $log->properties);
        $this->assertStringNotContainsString('/hooks/loop7', (string) $log->properties);
    }

    public function test_prune_borra_entregas_antiguas_resueltas(): void
    {
        [$owner, $org] = $this->proOrg();
        [$endpoint] = $this->createEndpoint($owner, $org);
        $base = ['organization_id' => $org->id, 'webhook_endpoint_id' => $endpoint->id, 'event' => 'content.published', 'message_id' => 'msg_x', 'payload' => []];
        $old = WebhookDelivery::query()->create($base + ['status' => 'succeeded']);
        $pending = WebhookDelivery::query()->create($base + ['status' => 'pending']);
        $recent = WebhookDelivery::query()->create($base + ['status' => 'failed']);
        DB::table('webhook_deliveries')->whereIn('id', [$old->id, $pending->id])->update(['created_at' => now()->subDays(40)]);

        $this->artisan('webhooks:prune')->assertSuccessful();

        $this->assertDatabaseMissing('webhook_deliveries', ['id' => $old->id]);
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $pending->id]);
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $recent->id]);
    }
}
