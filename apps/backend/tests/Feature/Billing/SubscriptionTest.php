<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.plan', 'growth');

        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $org->id,
            'status' => 'trialing',
        ]);
    }

    public function test_suscribirse_con_pasarela_manual_activa_el_plan(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', [
                'plan' => 'professional',
                'interval' => 'month',
                'gateway' => 'manual',
            ])
            ->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $org->id,
            'status' => 'active',
            'gateway' => 'manual',
        ]);
    }

    public function test_no_se_puede_suscribir_con_pasarela_deshabilitada(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        // Stripe está deshabilitada por defecto.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', [
                'plan' => 'professional',
                'interval' => 'month',
                'gateway' => 'stripe',
            ])
            ->assertStatus(422);
    }

    public function test_cancelar_marca_la_suscripcion_para_fin_de_periodo(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/cancel')
            ->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $org->id,
            'cancel_at_period_end' => true,
        ]);
    }
}
