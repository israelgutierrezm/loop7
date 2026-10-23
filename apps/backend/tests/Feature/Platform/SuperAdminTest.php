<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_no_autenticado_recibe_401(): void
    {
        $this->getJson('/api/v1/platform/dashboard')->assertUnauthorized();
    }

    public function test_usuario_normal_no_accede_al_panel_de_plataforma(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_superadmin_accede_al_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->getJson('/api/v1/platform/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'organizations' => ['total', 'active', 'suspended'],
                    'users' => ['total', 'platform_admins'],
                ],
            ]);
    }

    public function test_con_mfa_obligatorio_el_panel_exige_el_doble_factor(): void
    {
        config(['platform.require_mfa_for_superadmin' => true]);
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/platform/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_required');
        // Su perfil (donde lo activa) sigue disponible.
        $this->getJson('/api/v1/me')->assertOk();

        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->getJson('/api/v1/platform/dashboard')->assertOk();
    }

    public function test_superadmin_puede_impersonar_y_finalizar(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$target] = $this->createOwnerWithOrganization();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/platform/impersonate/' . $target->public_id)
            ->assertOk()
            ->assertJsonPath('data.email', $target->email);

        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.impersonation_started']);
    }
}
