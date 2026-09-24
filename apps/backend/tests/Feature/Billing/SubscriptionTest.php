<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Payments\Models\Invoice;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\PlatformAdmin\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_una_organizacion_nueva_arranca_en_trial(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        $response = $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.plan', 'growth');

        $this->assertSame(0, $response->json('data.usage')['brands.max']);
        $this->assertSame(1, $response->json('data.usage')['team_members.max']);

        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'status' => 'trialing']);
    }

    public function test_el_trial_usa_el_plan_y_los_dias_configurados(): void
    {
        app(PlatformSettings::class)->set(['billing.trial_plan' => 'starter', 'billing.trial_days' => 30]);
        \App\Modules\Billing\Models\Plan::query()->where('key', 'starter')->update(['trial_days' => 0]);

        [, $org] = $this->createOwnerWithOrganization();
        $subscription = Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        $this->assertSame('starter', $subscription->plan?->key);
        $this->assertEqualsWithDelta(30, now()->diffInDays($subscription->trial_ends_at), 1);
    }

    public function test_pago_manual_queda_pendiente_hasta_que_superadmin_lo_confirma(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        PaymentGateway::query()->where('key', 'manual')->firstOrFail()
            ->forceFill(['config' => ['currency' => 'USD', 'instructions' => 'Transfiere a la cuenta 123.']])->save();

        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'professional', 'interval' => 'month', 'gateway' => 'manual'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.message', 'Transfiere a la cuenta 123.')
            ->assertJsonPath('data.invoice.amount_cents', 9900);

        // Seguridad: elegir "manual" NO activa el plan por sí solo.
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'status' => 'trialing']);

        $invoiceId = (string) $response->json('data.invoice.id');
        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/billing/subscription')
            ->assertJsonPath('data.pending_invoice.id', $invoiceId);

        // SUPERADMIN confirma el pago → se activa el plan y se registra la transacción.
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $this->postJson("/api/v1/platform/invoices/{$invoiceId}/mark-paid", ['note' => 'Transferencia 0001'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'status' => 'active', 'gateway' => 'manual']);
        $this->assertDatabaseHas('payment_transactions', ['organization_id' => $org->id, 'status' => 'succeeded', 'amount_cents' => 9900]);
        $this->assertSame(Invoice::PAID, Invoice::query()->withoutGlobalScopes()->where('public_id', $invoiceId)->value('status'));

        // Confirmar dos veces no duplica nada.
        $this->postJson("/api/v1/platform/invoices/{$invoiceId}/mark-paid")->assertStatus(409);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_no_se_puede_suscribir_con_pasarela_deshabilitada(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        // Stripe está deshabilitada por defecto.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'professional', 'interval' => 'month', 'gateway' => 'stripe'])
            ->assertStatus(422);
    }

    public function test_el_plan_debe_tener_precio_en_la_moneda_de_la_pasarela(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        PaymentGateway::query()->where('key', 'mercadopago')->update(['is_enabled' => true]); // cobra en MXN

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'professional', 'interval' => 'month', 'gateway' => 'mercadopago'])
            ->assertStatus(422)
            ->assertJsonFragment(['El plan Professional no tiene precio mensual en MXN para esta pasarela.']);
    }

    public function test_cancelar_y_reanudar(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional');

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/billing/cancel')->assertOk();
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'cancel_at_period_end' => true]);

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/billing/resume')->assertOk();
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'cancel_at_period_end' => false]);
    }

    public function test_sincronizacion_aplica_vencimientos_gracia_y_suspension(): void
    {
        app(PlatformSettings::class)->set(['billing.grace_days' => 7]);

        [, $trialOrg] = $this->createOwnerWithOrganization();
        Subscription::query()->withoutGlobalScopes()->where('organization_id', $trialOrg->id)
            ->update(['trial_ends_at' => now()->subHour()]);

        [, $renewOrg] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($renewOrg, 'growth');
        Subscription::query()->withoutGlobalScopes()->where('organization_id', $renewOrg->id)
            ->update(['current_period_end' => now()->subDay()]);

        [, $lateOrg] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($lateOrg, 'growth');
        Subscription::query()->withoutGlobalScopes()->where('organization_id', $lateOrg->id)
            ->update(['status' => 'grace', 'current_period_end' => now()->subDays(8)]);

        [, $cancelOrg] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($cancelOrg, 'growth');
        Subscription::query()->withoutGlobalScopes()->where('organization_id', $cancelOrg->id)
            ->update(['cancel_at_period_end' => true, 'current_period_end' => now()->subMinute()]);

        $this->artisan('billing:sync-subscriptions')->assertSuccessful();

        $status = fn ($org) => Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail()->status->value;
        $this->assertSame('expired', $status($trialOrg));
        $this->assertSame('grace', $status($renewOrg));      // conserva el acceso durante la gracia
        $this->assertSame('suspended', $status($lateOrg));
        $this->assertSame('cancelled', $status($cancelOrg));
    }

    public function test_sin_suscripcion_vigente_no_hay_acceso_a_funciones(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->update(['status' => 'expired']);

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/brands', ['name' => 'Nueva'])
            ->assertStatus(402);
    }

    public function test_el_registro_puede_cerrarse_desde_la_configuracion(): void
    {
        app(PlatformSettings::class)->set(['registration.open' => false]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'Secreta123!',
            'password_confirmation' => 'Secreta123!', 'accept_terms' => true,
        ])->assertStatus(403)->assertJsonPath('code', 'registration_closed');

        $this->getJson('/api/v1/public-config')->assertOk()->assertJsonPath('data.registration_open', false);
    }
}
