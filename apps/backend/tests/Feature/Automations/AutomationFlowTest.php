<?php

declare(strict_types=1);

namespace Tests\Feature\Automations;

use App\Models\User;
use App\Modules\Automations\Flow\AutomationFlow;
use App\Modules\Automations\Flow\ConditionEvaluator;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Models\AutomationRun;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Editor visual (docs/05): flujos con condiciones Sí/No, esperas y acciones;
 * errores por paso, ejecución con traza, reanudación tras una espera y «Probar».
 */
class AutomationFlowTest extends TestCase
{
    use RefreshDatabase;

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
        $this->setOrganizationPlan($org, 'professional'); // feature.automations
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Aurora']);

        return [$owner, $org, $brand];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function action(string $id, string $type, array $config): array
    {
        return ['id' => $id, 'type' => 'action', 'action' => $type, 'config' => $config];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function automation(Organization $org, array $steps, string $trigger = 'content.published'): Automation
    {
        return Automation::query()->create([
            'organization_id' => $org->id,
            'name' => 'Flujo de prueba',
            'is_enabled' => true,
            'trigger' => $trigger,
            'flow' => ['steps' => $steps],
        ]);
    }

    private function publish(Organization $org, Brand $brand, string $title): ContentItem
    {
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => $title, 'status' => 'published',
        ]);
        event(new ContentPublished($content, 'published'));

        return $content;
    }

    /**
     * @return list<string>
     */
    private function noticeBodies(): array
    {
        return DB::table('notifications')->where('type', 'automation.notify')->orderBy('created_at')->pluck('data')
            ->map(fn (string $data) => (string) json_decode($data, true)['body'])->unique()->values()->all();
    }

    public function test_guardar_un_flujo_con_condicion_espera_y_acciones(): void
    {
        [$owner, $org] = $this->proOrg();

        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations', [
                'name' => 'Ofertas',
                'trigger' => 'content.published',
                'flow' => ['steps' => [[
                    'id' => 'b1', 'type' => 'branch', 'match' => 'any',
                    'conditions' => [
                        ['field' => 'content_title', 'operator' => 'contains', 'value' => 'oferta'],
                        ['field' => 'brand', 'operator' => 'is_empty', 'value' => 'se ignora'],
                    ],
                    'yes' => [
                        $this->action('n1', 'notify', ['message' => 'Nueva oferta: {content_title}', 'otra' => 'x']),
                        ['id' => 'w1', 'type' => 'wait', 'amount' => '2', 'unit' => 'hours'],
                        $this->action('h1', 'webhook', ['url' => 'https://93.184.216.34/hook']),
                    ],
                    'no' => [$this->action('n2', 'notify', ['message' => 'Publicado {content_title}', 'audience' => 'team'])],
                ]]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.steps_count', 5)
            ->assertJsonPath('data.actions_count', 3);

        $flow = $response->json('data.flow.steps.0');
        $this->assertSame('any', $flow['match']);
        $this->assertSame('', $flow['conditions'][1]['value'], '«está vacío» no lleva valor');
        // Sólo los campos de la acción y la audiencia por defecto.
        $this->assertSame(['message' => 'Nueva oferta: {content_title}', 'audience' => 'managers'], $flow['yes'][0]['config']);
        $this->assertSame(['id' => 'w1', 'type' => 'wait', 'amount' => 2, 'unit' => 'hours'], $flow['yes'][1]);
        $this->assertSame('team', $flow['no'][0]['config']['audience']);

        $this->assertSame($flow, Automation::query()->sole()->flow['steps'][0]);
    }

    public function test_errores_por_paso(): void
    {
        [$owner, $org] = $this->proOrg();
        $save = fn (array $steps) => $this->actingInOrganization($owner, $org)->postJson('/api/v1/automations', [
            'name' => 'Con errores', 'trigger' => 'content.published', 'flow' => ['steps' => $steps],
        ]);

        $save([
            $this->action('n1', 'notify', ['message' => '']),
            $this->action('h1', 'webhook', ['url' => 'http://169.254.169.254/latest']),
            $this->action('x1', 'inbox_reply', ['message' => 'Hola']), // sólo con el inbox
            ['id' => 'b1', 'type' => 'branch', 'conditions' => [['field' => 'content title!', 'operator' => 'parece']], 'yes' => [], 'no' => []],
            $this->action('d1', 'nada', []),
            ['id' => 'w1', 'type' => 'wait', 'amount' => 31, 'unit' => 'days'],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'flow.n1.message', 'flow.h1.url', 'flow.x1.action', 'flow.d1.action',
            'flow.b1', // una condición debe ser el último paso de su camino
            'flow.b1.conditions.0.field', 'flow.b1.conditions.0.operator',
            'flow.w1', 'flow.w1.amount', // al final y demasiado larga
        ]);

        // Sin acciones, con pasos repetidos o demasiado anidado: error general.
        $save([['id' => 'w1', 'type' => 'wait', 'amount' => 1, 'unit' => 'hours'], ['id' => 'w2', 'type' => 'wait', 'amount' => 1, 'unit' => 'hours']])
            ->assertStatus(422)->assertJsonPath('errors.flow.0', 'Añade al menos una acción.');
        $save([$this->action('a1', 'notify', ['message' => 'x']), $this->action('a1', 'notify', ['message' => 'y'])])
            ->assertStatus(422)->assertJsonPath('errors.flow.0', 'Hay pasos repetidos en el flujo.');

        $nested = [$this->action('a1', 'notify', ['message' => 'x'])];
        foreach (range(1, 5) as $level) {
            $nested = [['id' => "b{$level}", 'type' => 'branch', 'conditions' => [['field' => 'brand', 'operator' => 'is_not_empty']], 'yes' => $nested, 'no' => []]];
        }
        $save($nested)->assertStatus(422)->assertJsonValidationErrors('flow.b1');

        $save(['no es una lista' => true])->assertStatus(422)->assertJsonValidationErrors('flow');
        $this->assertSame(0, Automation::query()->count());
    }

    public function test_la_condicion_sigue_solo_por_su_camino(): void
    {
        [, $org, $brand] = $this->proOrg();
        $automation = $this->automation($org, [[
            'id' => 'b1', 'type' => 'branch', 'match' => 'all',
            'conditions' => [['field' => 'content_title', 'operator' => 'contains', 'value' => 'oferta']],
            'yes' => [$this->action('si', 'notify', ['message' => 'Oferta: {content_title}'])],
            'no' => [$this->action('no', 'notify', ['message' => 'Sin oferta: {content_title}'])],
        ]]);

        $this->publish($org, $brand, 'Novedades de otoño');

        $this->assertSame(['Sin oferta: Novedades de otoño'], $this->noticeBodies());
        $run = AutomationRun::query()->sole();
        $this->assertSame('success', $run->status->value);
        $this->assertSame(['b1', 'no'], array_column($run->steps, 'id'));
        $this->assertSame('no', $run->steps[0]['path']);
        $this->assertSame(1, $automation->fresh()->run_count);

        // Camino «No» vacío: la ejecución queda omitida (como un filtro).
        $automation->update(['flow' => ['steps' => [[
            'id' => 'b1', 'type' => 'branch', 'conditions' => [['field' => 'content_title', 'operator' => 'starts_with', 'value' => 'Oferta']],
            'yes' => [$this->action('si', 'notify', ['message' => 'x'])], 'no' => [],
        ]]]]);
        $this->publish($org, $brand, 'Novedades de invierno');
        $this->assertSame('skipped', AutomationRun::query()->latest('id')->first()?->status->value);
        $this->assertSame(1, $automation->fresh()->run_count, 'las omitidas no cuentan');
    }

    public function test_operadores_y_coincidencia_todas_o_alguna(): void
    {
        $context = ['title' => 'Oferta de otoño', 'author' => '', 'tags' => 'café, té'];
        $branch = fn (string $match, array ...$conditions) => ['match' => $match, 'conditions' => $conditions];

        $this->assertTrue(ConditionEvaluator::matches($branch(
            'all',
            ['field' => 'title', 'operator' => 'starts_with', 'value' => 'oferta'],
            ['field' => 'author', 'operator' => 'is_empty'],
            ['field' => 'tags', 'operator' => 'is_not_empty'],
            ['field' => 'desconocido', 'operator' => 'is_empty'],
        ), $context));
        $this->assertFalse(ConditionEvaluator::matches($branch(
            'all',
            ['field' => 'title', 'operator' => 'contains', 'value' => 'otoño'],
            ['field' => 'tags', 'operator' => 'not_contains', 'value' => 'café'],
        ), $context));
        $this->assertTrue(ConditionEvaluator::matches($branch(
            'any',
            ['field' => 'title', 'operator' => 'equals', 'value' => 'otra'],
            ['field' => 'tags', 'operator' => 'contains', 'value' => 'TÉ'],
        ), $context));
        $this->assertFalse(ConditionEvaluator::matches($branch(
            'any',
            ['field' => 'title', 'operator' => 'equals', 'value' => 'otra'],
            ['field' => 'author', 'operator' => 'is_not_empty'],
        ), $context));
    }

    public function test_una_espera_deja_la_ejecucion_en_espera_y_se_retoma_al_vencer(): void
    {
        [, $org, $brand] = $this->proOrg();
        $automation = $this->automation($org, [
            $this->action('a1', 'notify', ['message' => 'Primero {content_title}']),
            ['id' => 'w1', 'type' => 'wait', 'amount' => 2, 'unit' => 'hours'],
            $this->action('a2', 'notify', ['message' => 'Dos horas después: {content_title}']),
        ]);

        $this->publish($org, $brand, 'Lanzamiento');

        $run = AutomationRun::query()->sole();
        $this->assertSame('waiting', $run->status->value);
        $this->assertSame('a2', $run->resume_step);
        $this->assertTrue($run->resume_at->between(now()->addHours(2)->subMinute(), now()->addHours(2)->addMinute()));
        $this->assertSame(['a1', 'w1'], array_column($run->steps, 'id'));
        $this->assertSame(['Primero Lanzamiento'], $this->noticeBodies());
        $this->assertSame(0, $automation->fresh()->run_count, 'aún no termina');

        // Antes de tiempo no se retoma.
        $this->artisan('automations:resume-waiting')->assertSuccessful();
        $this->assertSame('waiting', $run->fresh()->status->value);

        $this->travel(121)->minutes();
        $this->artisan('automations:resume-waiting')->assertSuccessful();

        $run->refresh();
        $this->assertSame('success', $run->status->value);
        $this->assertNull($run->resume_at);
        $this->assertSame(['a1', 'w1', 'a2'], array_column($run->steps, 'id'));
        $this->assertSame(['Primero Lanzamiento', 'Dos horas después: Lanzamiento'], $this->noticeBodies());
        $this->assertSame(1, $automation->fresh()->run_count);

        // Ya terminada, no se vuelve a retomar.
        $this->artisan('automations:resume-waiting')->assertSuccessful();
        $this->assertCount(2, $this->noticeBodies());
    }

    public function test_al_retomar_se_cancela_si_la_regla_ya_no_puede_seguir(): void
    {
        [$owner, $org, $brand] = $this->proOrg();
        $steps = [
            ['id' => 'w1', 'type' => 'wait', 'amount' => 10, 'unit' => 'minutes'],
            $this->action('a1', 'notify', ['message' => 'Tras la espera']),
        ];
        $automation = $this->automation($org, $steps);

        // 1) Pausada mientras esperaba.
        $this->publish($org, $brand, 'Uno');
        $automation->update(['is_enabled' => false]);
        $this->travel(11)->minutes();
        $this->artisan('automations:resume-waiting')->assertSuccessful();
        $first = AutomationRun::query()->sole();
        $this->assertSame('cancelled', $first->status->value);
        $this->assertSame('La automatización está en pausa o se eliminó.', $first->message);

        // 2) Se quitó el paso siguiente al editarla.
        $automation->update(['is_enabled' => true]);
        $this->publish($org, $brand, 'Dos');
        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/automations/{$automation->public_id}", [
                'name' => 'Editada', 'trigger' => 'content.published',
                'flow' => ['steps' => [$steps[0], $this->action('a9', 'notify', ['message' => 'Otro paso'])]],
            ])
            ->assertOk();
        $this->travel(11)->minutes();
        $this->artisan('automations:resume-waiting')->assertSuccessful();
        $this->assertSame('El paso siguiente ya no existe: se editó la automatización.', AutomationRun::query()->latest('id')->first()?->message);

        // 3) El plan dejó de incluir automatizaciones.
        $this->publish($org, $brand, 'Tres');
        $this->setOrganizationPlan($org, 'starter');
        $this->travel(11)->minutes();
        $this->artisan('automations:resume-waiting')->assertSuccessful();
        $this->assertSame('Tu plan ya no incluye automatizaciones.', AutomationRun::query()->latest('id')->first()?->message);

        $this->assertSame([], $this->noticeBodies(), 'ninguna acción tras la espera');
    }

    public function test_probar_recorre_el_flujo_sin_ejecutar_nada(): void
    {
        Http::fake();
        [$owner, $org, $brand] = $this->proOrg();
        $flow = ['steps' => [[
            'id' => 'b1', 'type' => 'branch', 'match' => 'all',
            'conditions' => [['field' => 'text', 'operator' => 'contains', 'value' => 'envíos']],
            'yes' => [
                $this->action('r1', 'inbox_reply', ['message' => 'Hola {participant}, sí enviamos.']),
                ['id' => 'w1', 'type' => 'wait', 'amount' => 1, 'unit' => 'days'],
                $this->action('h1', 'webhook', ['url' => 'https://93.184.216.34/hook']),
                $this->action('d1', 'create_draft', ['title' => 'Pregunta de {participant}', 'body' => '{text}']),
            ],
            'no' => [$this->action('t1', 'inbox_tag', ['tag' => 'otros'])],
        ]]];

        // Sin datos: los de ejemplo del disparador (el texto pregunta por envíos).
        $data = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations/simulate', ['trigger' => 'inbox.message_received', 'brand' => $brand->public_id, 'flow' => $flow])
            ->assertOk()
            ->json('data');

        $this->assertSame('success', $data['status']);
        $this->assertSame(['b1', 'r1', 'w1', 'h1', 'd1'], array_column($data['steps'], 'id'));
        $this->assertSame('yes', $data['steps'][0]['path']);
        $this->assertSame([['label' => 'Respuesta', 'value' => 'Hola Ana López, sí enviamos.']], $data['steps'][1]['preview']);
        $this->assertSame('Esperaría 1 día', $data['steps'][2]['message']);
        $this->assertSame('Pregunta de Ana López', $data['steps'][4]['preview'][0]['value']);

        // Con datos propios, por el otro camino.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations/simulate', [
                'trigger' => 'inbox.message_received', 'brand' => $brand->public_id, 'flow' => $flow,
                'context' => ['text' => 'Me encantó', 'participant' => 'Luis'],
            ])
            ->assertOk()
            ->assertJsonPath('data.steps.0.path', 'no')
            ->assertJsonPath('data.steps.1.preview.0.value', 'otros');

        // Nada se ejecutó: ni webhooks, ni avisos, ni borradores, ni ejecuciones.
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('notifications')->where('type', 'automation.notify')->count());
        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, AutomationRun::query()->count());

        // Un flujo inválido se rechaza igual que al guardar.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations/simulate', ['trigger' => 'content.published', 'flow' => $flow])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['flow.r1.action', 'flow.t1.action', 'flow.d1.action']);
    }

    public function test_el_historial_muestra_la_traza_y_las_variables_conocidas(): void
    {
        [$owner, $org] = $this->proOrg();
        $data = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/automations', [
                'name' => 'Pedidos', 'trigger' => 'webhook.received',
                'flow' => ['steps' => [[
                    'id' => 'b1', 'type' => 'branch', 'conditions' => [['field' => 'pedido.total', 'operator' => 'is_not_empty']],
                    'yes' => [$this->action('n1', 'notify', ['message' => 'Pedido de {cliente.nombre}'])], 'no' => [],
                ]]],
            ])
            ->assertCreated()
            ->json('data');

        $this->postJson((string) parse_url((string) $data['inbound_url'], PHP_URL_PATH), [
            'cliente' => ['nombre' => 'Ana'], 'pedido' => ['total' => '250'],
        ])->assertStatus(202);

        $show = $this->actingInOrganization($owner, $org)->getJson("/api/v1/automations/{$data['id']}")->assertOk();
        $show->assertJsonPath('data.runs.0.status', 'success')
            ->assertJsonPath('data.runs.0.steps.0.id', 'b1')
            ->assertJsonPath('data.runs.0.steps.0.path', 'yes')
            ->assertJsonPath('data.runs.0.steps.1.id', 'n1');
        $this->assertEqualsCanonicalizing(['cliente.nombre', 'pedido.total'], $show->json('data.fields'));
    }

    public function test_la_migracion_convierte_condiciones_y_acciones_en_un_flujo(): void
    {
        $migration = require base_path('app/Modules/Automations/Database/Migrations/2026_10_07_100001_add_flow_to_automations_table.php');
        $toSteps = new ReflectionMethod($migration, 'toSteps');
        $legacyActions = new ReflectionMethod($migration, 'legacyActions');

        $conditions = [['field' => 'content_status', 'operator' => 'equals', 'value' => 'published']];
        $actions = [['type' => 'notify', 'config' => ['message' => 'Hola']], ['type' => 'webhook', 'config' => ['url' => 'https://93.184.216.34/h']]];

        // Lo mismo que el formato que entiende el motor.
        $steps = $toSteps->invoke($migration, $conditions, $actions);
        $this->assertSame(AutomationFlow::fromLegacy($conditions, $actions)->steps(), $steps);
        $this->assertSame('branch', $steps[0]['type']);
        $this->assertSame(['a1', 'a2'], array_column($steps[0]['yes'], 'id'));
        $this->assertSame(AutomationFlow::fromLegacy([], $actions)->steps(), $toSteps->invoke($migration, [], $actions));

        // Y la vuelta atrás recupera las acciones.
        $this->assertSame($actions, $legacyActions->invoke($migration, $steps[0]['yes']));
    }
}
