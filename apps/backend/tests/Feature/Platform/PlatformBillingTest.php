<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Payments\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * Lee una clave con puntos (p. ej. "brands.max") de un objeto JSON de la respuesta.
     */
    private function jsonKey(TestResponse $response, string $path, string $key): mixed
    {
        return ((array) $response->json($path))[$key] ?? null;
    }

    private function actAsSuperadmin(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
    }

    public function test_superadmin_crea_edita_y_elimina_planes(): void
    {
        $this->actAsSuperadmin();

        $created = $this->postJson('/api/v1/platform/plans', [
            'key' => 'pymes', 'name' => 'Pymes', 'trial_days' => 7,
            'prices' => [
                ['interval' => 'month', 'currency' => 'mxn', 'amount_cents' => 49900],
                ['interval' => 'year', 'currency' => 'MXN', 'amount_cents' => 499000],
            ],
            'entitlements' => ['brands.max' => 2, 'feature.inbox' => true, 'ai_credits.month' => -1],
        ])->assertCreated()
            ->assertJsonPath('data.prices.0.currency', 'MXN');
        $this->assertTrue($this->jsonKey($created, 'data.entitlements', 'feature.inbox'));

        $this->putJson('/api/v1/platform/plans/pymes', [
            'name' => 'Pymes Plus',
            'prices' => [['interval' => 'month', 'currency' => 'MXN', 'amount_cents' => 59900]],
            'entitlements' => ['brands.max' => 5],
        ])->assertOk()->assertJsonPath('data.name', 'Pymes Plus');

        $this->assertDatabaseCount('plan_prices', 5 * 2 + 1); // 5 planes semilla × 2 + el nuevo (se quitó el anual)
        $this->assertDatabaseHas('plan_entitlements', ['entitlement_key' => 'brands.max', 'value' => '5']);

        // Validaciones: límite desconocido y precio repetido.
        $this->putJson('/api/v1/platform/plans/pymes', ['entitlements' => ['competitors.max' => 3]])->assertStatus(422);
        $this->putJson('/api/v1/platform/plans/pymes', ['prices' => [
            ['interval' => 'month', 'currency' => 'MXN', 'amount_cents' => 1],
            ['interval' => 'month', 'currency' => 'MXN', 'amount_cents' => 2],
        ]])->assertStatus(422);

        $this->deleteJson('/api/v1/platform/plans/pymes')->assertOk();
        // Un plan con suscripciones no se elimina (se desactiva).
        $this->createOwnerWithOrganization(); // trial en growth
        $this->deleteJson('/api/v1/platform/plans/growth')->assertStatus(409);
    }

    public function test_excepciones_y_add_ons_por_organizacion(): void
    {
        [, $org] = $this->createOwnerWithOrganization(); // growth: 3 marcas, sin automatizaciones
        $this->actAsSuperadmin();

        $saved = $this->putJson("/api/v1/platform/organizations/{$org->public_id}/billing/overrides", ['overrides' => [
            ['key' => 'feature.automations', 'value' => true, 'note' => 'Piloto'],
            ['key' => 'brands.max', 'value' => 20],
        ]])->assertOk();
        $this->assertTrue($this->jsonKey($saved, 'data', 'feature.automations'));
        $this->assertSame(20, $this->jsonKey($saved, 'data', 'brands.max'));

        $addOns = $this->putJson("/api/v1/platform/organizations/{$org->public_id}/billing/add-ons", ['add_ons' => [
            ['key' => 'ai-credits-1000', 'quantity' => 2],
        ]])->assertOk();
        $this->assertSame(500 + 2000, $this->jsonKey($addOns, 'data', 'ai_credits.month'));

        $detail = $this->getJson("/api/v1/platform/organizations/{$org->public_id}/billing")->assertOk();
        $this->assertCount(2, $detail->json('data.overrides'));
        $this->assertSame(2, $detail->json('data.add_ons.0.quantity'));

        // Quitar todas las excepciones.
        $cleared = $this->putJson("/api/v1/platform/organizations/{$org->public_id}/billing/overrides", ['overrides' => []])->assertOk();
        $this->assertSame(3, $this->jsonKey($cleared, 'data', 'brands.max'));
    }

    public function test_ampliar_trial_cambiar_plan_y_cancelar(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $this->actAsSuperadmin();
        $before = Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->value('trial_ends_at');

        $this->postJson("/api/v1/platform/organizations/{$org->public_id}/billing/extend-trial", ['days' => 10])->assertOk();
        $after = Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $this->assertTrue($after->trial_ends_at->greaterThan($before));

        $this->postJson("/api/v1/platform/organizations/{$org->public_id}/billing/plan", ['plan' => 'agency', 'interval' => 'year'])->assertOk();
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'status' => 'active', 'interval' => 'year']);

        $this->postJson("/api/v1/platform/organizations/{$org->public_id}/billing/cancel", ['immediately' => true])->assertOk();
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'status' => 'cancelled']);

        $this->getJson('/api/v1/platform/subscriptions?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_anular_factura_pendiente(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $id = (string) $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter', 'interval' => 'month', 'gateway' => 'manual'])
            ->json('data.invoice.id');

        $this->actAsSuperadmin();
        $this->getJson('/api/v1/platform/invoices?status=open')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/platform/invoices/{$id}/void")->assertOk()->assertJsonPath('data.status', Invoice::VOID);
        $this->postJson("/api/v1/platform/invoices/{$id}/void")->assertStatus(409);
    }

    public function test_configuracion_de_plataforma_y_datos_publicos(): void
    {
        $this->actAsSuperadmin();

        $settings = $this->putJson('/api/v1/platform/settings', [
            'company' => ['name' => 'Loop7', 'legal_name' => 'Loop7 S.A. de C.V.', 'contact_email' => 'legal@loop7.test', 'country' => 'México'],
            'billing' => ['trial_plan' => 'starter', 'grace_days' => 3],
            'announcement' => ['enabled' => true, 'message' => 'Mantenimiento el domingo 2:00', 'tone' => 'warning'],
        ])->assertOk();
        $this->assertSame('Loop7 S.A. de C.V.', $this->jsonKey($settings, 'data', 'company.legal_name'));
        $this->assertSame(3, $this->jsonKey($settings, 'data', 'billing.grace_days'));

        $this->putJson('/api/v1/platform/settings', ['billing' => ['trial_plan' => 'inexistente']])->assertStatus(422);

        $this->getJson('/api/v1/public-config')
            ->assertOk()
            ->assertJsonPath('data.company.legal_name', 'Loop7 S.A. de C.V.')
            ->assertJsonPath('data.company.contact_email', 'legal@loop7.test')
            ->assertJsonPath('data.announcement.tone', 'warning');
    }

    public function test_auditoria_global_filtra_por_accion(): void
    {
        $this->createOwnerWithOrganization(); // genera organization.created, subscription.trial_started…
        $this->actAsSuperadmin();

        $response = $this->getJson('/api/v1/platform/audit-logs?action=subscription.')->assertOk();
        $actions = collect($response->json('data'))->pluck('action')->unique()->values()->all();

        $this->assertSame(['subscription.trial_started'], $actions);
        $this->assertNotNull($response->json('data.0.organization.name'));
    }

    public function test_dashboard_de_plataforma_con_metricas_de_negocio(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'growth');
        $this->actAsSuperadmin();

        $this->getJson('/api/v1/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.subscriptions.by_status.active', 1)
            ->assertJsonPath('data.revenue.open_invoices', 0)
            ->assertJsonStructure(['data' => [
                'revenue' => ['mrr', 'last_30_days', 'conversions_30_days', 'churn_30_days', 'failed_payments_30_days'],
                'publishing' => ['published_7_days', 'failed_7_days', 'scheduled'],
                'ai' => ['credits_this_month', 'generations_30_days'],
                'operations' => ['failed_jobs', 'failed_webhooks', 'social_needing_attention'],
            ]]);
    }

    public function test_solo_superadmin_gestiona_el_billing(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)->getJson('/api/v1/platform/plans')->assertForbidden();
        $this->actingInOrganization($owner, $org)->putJson('/api/v1/platform/settings', [])->assertForbidden();
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/platform/organizations/{$org->public_id}/billing/plan", ['plan' => 'enterprise', 'interval' => 'month'])
            ->assertForbidden();
    }
}
