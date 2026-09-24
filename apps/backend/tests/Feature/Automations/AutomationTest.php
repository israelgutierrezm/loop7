<?php

declare(strict_types=1);

namespace Tests\Feature\Automations;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Automations\Models\Automation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Inbox\Services\InboxService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

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
        $this->setOrganizationPlan($org, 'professional'); // feature.automations = true

        return [$owner, $org];
    }

    /**
     * @param  list<array{type: string, config?: array<string, mixed>}>  $actions
     * @param  list<array{field: string, operator: string, value: string}>  $conditions
     */
    private function makeAutomation(Organization $org, string $trigger, array $actions, array $conditions = []): Automation
    {
        return Automation::query()->create([
            'organization_id' => $org->id,
            'brand_id' => null,
            'name' => 'Regla de prueba',
            'is_enabled' => true,
            'trigger' => $trigger,
            'conditions' => $conditions,
            'actions' => $actions,
        ]);
    }

    public function test_crear_y_listar_automatizacion(): void
    {
        [$owner, $org] = $this->proOrg();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations', [
                'name' => 'Avisar al publicar',
                'trigger' => 'content.published',
                'actions' => [['type' => 'notify', 'config' => ['message' => 'Publicado {content_title}']]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.trigger', 'content.published');

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/automations')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_plan_sin_feature_devuelve_402(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization(); // trial = Growth (sin automatizaciones)

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/automations')
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.automations');
    }

    public function test_evento_content_published_ejecuta_acciones(): void
    {
        [, $org] = $this->proOrg();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Novedad', 'status' => 'published',
        ]);
        $automation = $this->makeAutomation($org, 'content.published', [
            ['type' => 'notify', 'config' => ['message' => 'Publicado {content_title}']],
        ]);

        event(new ContentPublished($content, 'published'));

        $this->assertSame(1, $automation->fresh()->run_count);
        $this->assertDatabaseHas('automation_runs', ['automation_id' => $automation->id, 'status' => 'success']);
    }

    public function test_condiciones_filtran_la_ejecucion(): void
    {
        [, $org] = $this->proOrg();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Novedad', 'status' => 'partial',
        ]);
        $automation = $this->makeAutomation(
            $org,
            'content.published',
            [['type' => 'notify', 'config' => ['message' => 'ok']]],
            [['field' => 'content_status', 'operator' => 'equals', 'value' => 'published']],
        );

        event(new ContentPublished($content, 'partial')); // no cumple la condición

        $this->assertDatabaseHas('automation_runs', ['automation_id' => $automation->id, 'status' => 'skipped']);
    }

    public function test_accion_webhook_llama_a_la_url(): void
    {
        Http::fake();
        [, $org] = $this->proOrg();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Novedad', 'status' => 'published',
        ]);
        // IP pública literal: la validación anti-SSRF no depende del DNS en los tests.
        $automation = $this->makeAutomation($org, 'content.published', [
            ['type' => 'webhook', 'config' => ['url' => 'https://93.184.216.34/hook']],
        ]);

        event(new ContentPublished($content, 'published'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '93.184.216.34')
            && $request['context']['content_id'] === $content->public_id);
        $this->assertDatabaseHas('automation_runs', ['automation_id' => $automation->id, 'status' => 'success']);
    }

    public function test_webhook_hacia_red_interna_se_rechaza(): void
    {
        Http::fake();
        [$owner, $org] = $this->proOrg();

        foreach ([
            'http://127.0.0.1:6379/',
            'http://169.254.169.254/latest/meta-data/',
            'http://10.0.0.8/admin',
            'http://localhost/hook',
            'http://servicio.internal/hook',
            'ftp://93.184.216.34/hook',
            'https://usuario:clave@93.184.216.34/hook',
        ] as $url) {
            $this->actingInOrganization($owner, $org)
                ->postJson('/api/v1/automations', [
                    'name' => 'SSRF', 'trigger' => 'content.published',
                    'actions' => [['type' => 'webhook', 'config' => ['url' => $url]]],
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['actions.0.config.url']);
        }

        // Aunque la regla ya existiera (p. ej. el DNS cambió), al ejecutarse tampoco sale.
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'X', 'status' => 'published',
        ]);
        $automation = $this->makeAutomation($org, 'content.published', [
            ['type' => 'webhook', 'config' => ['url' => 'http://169.254.169.254/latest/meta-data/']],
        ]);

        event(new ContentPublished($content, 'published'));

        Http::assertNothingSent();
        $this->assertDatabaseHas('automation_runs', ['automation_id' => $automation->id, 'status' => 'failed']);
    }

    public function test_accion_avisar_notifica_a_la_audiencia_con_acceso_a_la_marca(): void
    {
        [$owner, $org] = $this->proOrg();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $approver = $this->addMember($org, OrganizationRole::APPROVER->value);
        $outsider = $this->addMember($org, OrganizationRole::APPROVER->value, allBrandsAccess: false);
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Novedad', 'status' => 'published',
        ]);
        $this->makeAutomation($org, 'content.published', [
            ['type' => 'notify', 'config' => ['message' => 'Revisen {content_title}', 'audience' => 'approvers']],
        ]);

        event(new ContentPublished($content, 'published'));

        $notified = DB::table('notifications')->where('type', 'automation.notify')->pluck('notifiable_id')->sort()->values()->all();
        $this->assertSame(collect([$owner->id, $approver->id])->sort()->values()->all(), $notified);
        $this->assertNotContains($outsider->id, $notified); // sin acceso a la marca
        $this->assertNotContains($creator->id, $notified);  // no es aprobador

        $row = DB::table('notifications')->where('notifiable_id', $approver->id)->first();
        $this->assertSame($org->id, (int) $row->organization_id);
        $this->assertSame('Revisen Novedad', json_decode($row->data, true)['body']);
        $this->assertSame("/app/content/{$content->public_id}", json_decode($row->data, true)['path']);
    }

    public function test_auto_respuesta_de_inbox_por_evento(): void
    {
        [, $org] = $this->proOrg();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo',
        ]);
        SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'dest-1', 'name' => 'Página', 'type' => 'page',
        ]);
        $this->makeAutomation($org, 'inbox.message_received', [
            ['type' => 'inbox_reply', 'config' => ['message' => 'Hola {participant}, gracias por escribir.']],
        ]);

        // La sincronización crea mensajes entrantes y emite InboxMessageReceived.
        app(InboxService::class)->syncBrand($brand);

        $this->assertDatabaseHas('inbox_messages', ['type' => 'reply', 'author_name' => 'Automatización']);
        $this->assertDatabaseHas('automation_runs', ['status' => 'success']);
    }

    public function test_aislamiento_entre_organizaciones(): void
    {
        [, $orgA] = $this->proOrg();
        [, $orgB] = $this->proOrg();
        $brandA = Brand::factory()->create(['organization_id' => $orgA->id]);
        $contentA = ContentItem::query()->create([
            'organization_id' => $orgA->id, 'brand_id' => $brandA->id, 'title' => 'A', 'status' => 'published',
        ]);
        $autoA = $this->makeAutomation($orgA, 'content.published', [['type' => 'notify', 'config' => ['message' => 'a']]]);
        $autoB = $this->makeAutomation($orgB, 'content.published', [['type' => 'notify', 'config' => ['message' => 'b']]]);

        event(new ContentPublished($contentA, 'published'));

        $this->assertSame(1, $autoA->fresh()->run_count);
        $this->assertSame(0, $autoB->fresh()->run_count);
    }

    public function test_actualizar_y_eliminar(): void
    {
        [$owner, $org] = $this->proOrg();
        $automation = $this->makeAutomation($org, 'content.published', [['type' => 'notify', 'config' => ['message' => 'x']]]);

        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/automations/{$automation->public_id}", [
                'name' => 'Renombrada',
                'trigger' => 'content.published',
                'is_enabled' => false,
                'actions' => [['type' => 'notify', 'config' => ['message' => 'y']]],
            ])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);

        $this->actingInOrganization($owner, $org)
            ->deleteJson("/api/v1/automations/{$automation->public_id}")
            ->assertOk();

        $this->assertDatabaseMissing('automations', ['id' => $automation->id]);
    }
}
