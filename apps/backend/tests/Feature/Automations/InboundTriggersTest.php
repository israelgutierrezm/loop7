<?php

declare(strict_types=1);

namespace Tests\Feature\Automations;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Automations\Exceptions\FeedException;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Services\FeedReader;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboundTriggersTest extends TestCase
{
    use BuildsAutomationFlows;
    use RefreshDatabase;

    private const FEED = 'https://93.184.216.34/feed.xml';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Brand}
     */
    private function proOrg(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional'); // feature.automations = true
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Norte']);

        return [$owner, $org, $brand];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>  datos de la automatización creada (respuesta API)
     */
    private function createAutomation(User $owner, Organization $org, array $overrides = []): array
    {
        return $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations', [
                'name' => 'Pedidos a borrador',
                'trigger' => 'webhook.received',
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function inboundPath(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function rss(string ...$items): string
    {
        $xml = '';
        foreach ($items as $slug) {
            $xml .= "<item><title>Entrada {$slug}</title><link>https://blog.example.com/{$slug}</link>"
                . "<guid>id-{$slug}</guid><description><![CDATA[<p>Resumen <b>{$slug}</b></p>]]></description>"
                . '<dc:creator>Ana</dc:creator></item>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . "<channel><title>Blog de Café</title>{$xml}</channel></rss>";
    }

    // --- Webhook entrante ---

    public function test_webhook_entrante_crea_un_borrador_con_los_campos_del_json(): void
    {
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'create_draft', 'config' => [
                'title' => 'Pedido {pedido.id}',
                'body' => '{cliente.nombre} pidió {productos}',
            ]]]),
        ]);

        $this->assertStringContainsString('/api/v1/hooks/automations/', (string) $data['inbound_url']);
        $automation = Automation::query()->withoutGlobalScopes()->where('public_id', $data['id'])->firstOrFail();
        $token = basename((string) $data['inbound_url']);
        // Token cifrado en BD y buscable sólo por su hash.
        $this->assertSame(hash('sha256', $token), $automation->inbound_token_hash);
        $this->assertStringNotContainsString($token, (string) DB::table('automations')->where('id', $automation->id)->value('inbound_token'));

        $this->postJson($this->inboundPath($data['inbound_url']), [
            'pedido' => ['id' => 42],
            'cliente' => ['nombre' => 'Ana'],
            'productos' => ['café', 'pan'],
        ])->assertStatus(202);

        $content = ContentItem::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $this->assertSame('Pedido 42', $content->title);
        $this->assertSame('Ana pidió café, pan', $content->body);
        $this->assertSame('draft', $content->status->value);
        $this->assertDatabaseHas('automation_runs', ['automation_id' => $automation->id, 'status' => 'success', 'trigger' => 'webhook.received']);
        // Auditoría con la organización correcta aunque se cree desde un job.
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.created', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'automation.created', 'organization_id' => $org->id]);
    }

    public function test_webhook_entrante_rechaza_token_desconocido_regla_pausada_y_plan_sin_automatizaciones(): void
    {
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'notify', 'config' => ['message' => 'Llegó {evento}']]]),
        ]);
        $path = $this->inboundPath($data['inbound_url']);

        $this->postJson('/api/v1/hooks/automations/' . str_repeat('x', 48), [])->assertNotFound();
        $this->postJson('/api/v1/hooks/automations/corto', [])->assertNotFound();

        Automation::query()->withoutGlobalScopes()->where('public_id', $data['id'])->update(['is_enabled' => false]);
        $this->postJson($path, [])->assertStatus(409);

        Automation::query()->withoutGlobalScopes()->where('public_id', $data['id'])->update(['is_enabled' => true]);
        $this->setOrganizationPlan($org, 'growth');
        $this->postJson($path, [])->assertStatus(402);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_webhook_entrante_es_idempotente_con_idempotency_key(): void
    {
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'create_draft', 'config' => ['title' => 'Idea {n}']]]),
        ]);
        $path = $this->inboundPath($data['inbound_url']);

        $this->postJson($path, ['n' => 1], ['Idempotency-Key' => 'abc-1'])->assertStatus(202);
        $this->postJson($path, ['n' => 1], ['Idempotency-Key' => 'abc-1'])->assertOk();
        $this->postJson($path, ['n' => 2], ['Idempotency-Key' => 'abc-2'])->assertStatus(202);

        $this->assertSame(2, ContentItem::query()->withoutGlobalScopes()->count());
    }

    public function test_renovar_la_url_invalida_la_anterior(): void
    {
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'notify', 'config' => ['message' => 'Hola']]]),
        ]);

        $new = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/automations/{$data['id']}/rotate-inbound-url")
            ->assertOk()
            ->json('data.inbound_url');

        $this->assertNotSame($data['inbound_url'], $new);
        $this->postJson($this->inboundPath($data['inbound_url']), [])->assertNotFound();
        $this->postJson($this->inboundPath($new), [])->assertStatus(202);
        $this->assertDatabaseHas('audit_logs', ['action' => 'automation.inbound_url_rotated', 'organization_id' => $org->id]);
    }

    public function test_cambiar_de_disparador_retira_el_token(): void
    {
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'notify', 'config' => ['message' => 'Hola']]]),
        ]);

        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/automations/{$data['id']}", [
                'name' => 'Ahora al publicar',
                'trigger' => 'content.published',
                'brand' => $brand->public_id,
                'flow' => $this->flow([['type' => 'notify', 'config' => ['message' => 'Hola']]]),
            ])
            ->assertOk()
            ->assertJsonPath('data.inbound_url', null);

        $this->postJson($this->inboundPath($data['inbound_url']), [])->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'automation.updated', 'organization_id' => $org->id]);
    }

    // --- RSS ---

    public function test_rss_la_primera_lectura_memoriza_y_despues_dispara_las_entradas_nuevas(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->rss('b', 'a'), 200, ['ETag' => '"v1"'])
                ->push($this->rss('c', 'b', 'a'), 200, ['ETag' => '"v2"'])
                ->push('', 304),
        ]);
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'trigger' => 'rss.item_published',
            'trigger_config' => ['feed_url' => self::FEED],
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'create_draft', 'config' => ['title' => '{title}', 'body' => '{summary} {link} ({feed_title})']]]),
        ]);

        $this->artisan('automations:poll-feeds')->assertSuccessful();
        $this->assertSame(0, ContentItem::query()->withoutGlobalScopes()->count());

        $this->travel(16)->minutes();
        $this->artisan('automations:poll-feeds')->assertSuccessful();

        $content = ContentItem::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Entrada c', $content->title);
        $this->assertSame('Resumen c https://blog.example.com/c (Blog de Café)', $content->body);

        // Petición condicional: 304 = sin cambios.
        $this->travel(16)->minutes();
        $this->artisan('automations:poll-feeds')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v2"'));
        $this->assertSame(1, ContentItem::query()->withoutGlobalScopes()->count());

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/automations/{$data['id']}")
            ->assertOk()
            ->assertJsonPath('data.feed.title', 'Blog de Café')
            ->assertJsonPath('data.feed.last_error', null)
            ->assertJsonPath('data.feed.ready', true);
    }

    public function test_rss_no_sigue_redirecciones_a_direcciones_internas(): void
    {
        Http::fake(['*' => Http::response('', 301, ['Location' => 'http://127.0.0.1/admin/feed'])]);
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'trigger' => 'rss.item_published',
            'trigger_config' => ['feed_url' => self::FEED],
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'notify', 'config' => ['message' => '{title}']]]),
        ]);

        $this->artisan('automations:poll-feeds')->assertSuccessful();

        Http::assertSentCount(1); // nunca se pidió la URL interna
        $automation = Automation::query()->withoutGlobalScopes()->where('public_id', $data['id'])->firstOrFail();
        $this->assertStringContainsString('servidor público', (string) ($automation->state['last_error'] ?? ''));
    }

    public function test_rss_valida_la_url_del_feed_al_guardar(): void
    {
        [$owner, $org, $brand] = $this->proOrg();

        foreach (['', 'https://10.0.0.8/feed', 'file:///etc/passwd'] as $url) {
            $this->actingInOrganization($owner, $org)
                ->postJson('/api/v1/automations', [
                    'name' => 'Feed',
                    'trigger' => 'rss.item_published',
                    'trigger_config' => ['feed_url' => $url],
                    'brand' => $brand->public_id,
                    'flow' => $this->flow([['type' => 'notify', 'config' => ['message' => '{title}']]]),
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('trigger_config.feed_url');
        }
    }

    public function test_vista_previa_del_feed(): void
    {
        Http::fake(['*' => Http::response($this->rss('x', 'y'))]);
        [$owner, $org] = $this->proOrg();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations/feed-preview', ['url' => self::FEED])
            ->assertOk()
            ->assertJsonPath('data.title', 'Blog de Café')
            ->assertJsonPath('data.items.0.title', 'Entrada x')
            ->assertJsonPath('data.items.0.summary', 'Resumen x')
            ->assertJsonPath('data.items.0.author', 'Ana');

        Http::fake(['*' => Http::response('<html><body>No es un feed</body></html>')]);
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations/feed-preview', ['url' => self::FEED])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_lector_de_feeds_entiende_atom_y_rechaza_entidades(): void
    {
        $reader = app(FeedReader::class);

        $atom = $reader->parse('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Noticias</title>'
            . '<entry><title>Hola &amp; adiós</title><id>urn:1</id><link rel="alternate" href="https://example.com/1"/>'
            . '<updated>2026-09-01T10:00:00Z</updated><summary>Texto</summary><author><name>Luis</name></author></entry></feed>');
        $this->assertSame('Noticias', $atom->title);
        $this->assertSame('Hola & adiós', $atom->items[0]['title']);
        $this->assertSame('https://example.com/1', $atom->items[0]['link']);
        $this->assertSame('Luis', $atom->items[0]['author']);
        $this->assertSame('2026-09-01T10:00:00+00:00', $atom->items[0]['published_at']);

        $this->expectException(FeedException::class);
        $reader->parse('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><rss><channel><title>&b;</title></channel></rss>');
    }

    // --- Validaciones de acciones ---

    public function test_crear_borrador_exige_marca_y_permiso_y_las_acciones_de_inbox_su_disparador(): void
    {
        [$owner, $org, $brand] = $this->proOrg();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations', [
                'name' => 'Sin marca',
                'trigger' => 'webhook.received',
                'flow' => $this->flow([['type' => 'create_draft', 'config' => ['title' => '{t}']]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('flow.a1.action');

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations', [
                'name' => 'Inbox fuera de lugar',
                'trigger' => 'rss.item_published',
                'trigger_config' => ['feed_url' => self::FEED],
                'brand' => $brand->public_id,
                'flow' => $this->flow([['type' => 'inbox_reply', 'config' => ['message' => 'Hola']]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('flow.a1.action');

        // Un rol con automatizaciones pero sin crear contenido no puede crear borradores por esta vía.
        $billing = $this->addMember($org, OrganizationRole::BILLING->value);
        $billing->givePermissionTo('automations.view', 'automations.create');
        $this->actingInOrganization($billing->fresh(), $org)
            ->postJson('/api/v1/automations', [
                'name' => 'Escalada',
                'trigger' => 'webhook.received',
                'brand' => $brand->public_id,
                'flow' => $this->flow([['type' => 'create_draft', 'config' => ['title' => '{t}']]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('flow.a1.action');
    }

    public function test_eliminar_automatizacion_queda_auditado(): void
    {
        [$owner, $org, $brand] = $this->proOrg();
        $data = $this->createAutomation($owner, $org, [
            'brand' => $brand->public_id,
            'flow' => $this->flow([['type' => 'notify', 'config' => ['message' => 'Hola']]]),
        ]);

        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/automations/{$data['id']}")->assertOk();

        $log = DB::table('audit_logs')->where('action', 'automation.deleted')->where('organization_id', $org->id)->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString(basename((string) $data['inbound_url']), (string) $log->properties);
    }
}
