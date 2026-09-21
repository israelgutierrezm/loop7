<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\AccessControl\Enums\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Criterio docs/15: el plan limita brands y miembros.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_el_plan_limita_el_numero_de_marcas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // brands.max = 1

        // Primera marca: OK.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/brands', ['name' => 'Marca 1'])
            ->assertCreated();

        // Segunda marca: bloqueada por el plan (HTTP 402).
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/brands', ['name' => 'Marca 2'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_el_plan_limita_el_numero_de_miembros(): void
    {
        Notification::fake();
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // team_members.max = 2 (owner + 1)

        // Primera invitación: OK (owner=1 + 1 invitación = 2).
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/invitations', [
                'email' => 'uno@example.com',
                'role' => OrganizationRole::VIEWER->value,
            ])
            ->assertCreated();

        // Segunda invitación: excede el plan.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/invitations', [
                'email' => 'dos@example.com',
                'role' => OrganizationRole::VIEWER->value,
            ])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }
}
