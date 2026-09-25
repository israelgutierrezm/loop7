<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_bloquear_impide_entrar_y_corta_la_sesion(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$user, $org] = $this->createOwnerWithOrganization(['email' => 'ana@loop7.test', 'password' => 'Secreta-123']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/platform/users/{$user->public_id}/block")->assertOk()->assertJsonPath('data.blocked', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.user_blocked']);
        $this->getJson('/api/v1/platform/users?blocked=1')->assertJsonCount(1, 'data');

        // La sesión activa se corta en la siguiente petición (el guard relee al
        // usuario de la base en cada petición; aquí se simula con fresh())…
        $this->actingInOrganization($user->fresh(), $org)->getJson('/api/v1/context')
            ->assertForbidden()->assertJsonPath('code', 'account_blocked');

        // …y el login lo rechaza (sólo con la contraseña correcta).
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@loop7.test', 'password' => 'Secreta-123'])
            ->assertForbidden()->assertJsonPath('code', 'account_blocked');
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@loop7.test', 'password' => 'mala'])
            ->assertStatus(422);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/platform/impersonate/{$user->public_id}")->assertStatus(422);
        $this->postJson("/api/v1/platform/users/{$user->public_id}/unblock")->assertOk()->assertJsonPath('data.blocked', false);
        $this->actingInOrganization($user->fresh(), $org)->getJson('/api/v1/context')->assertOk();
    }

    public function test_restablecer_el_doble_factor(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$user] = $this->createOwnerWithOrganization();
        $user->forceFill([
            'two_factor_secret' => 'SECRETO', 'two_factor_recovery_codes' => ['a', 'b'], 'two_factor_confirmed_at' => now(),
        ])->save();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/platform/users/{$user->public_id}/reset-two-factor")
            ->assertOk()->assertJsonPath('data.two_factor_enabled', false);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.user_mfa_reset']);
    }

    public function test_no_se_gestiona_a_otros_administradores_ni_a_uno_mismo(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $other = User::factory()->platformAdmin()->create();
        [$owner] = $this->createOwnerWithOrganization();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/platform/users/{$other->public_id}/block")->assertForbidden();
        $this->postJson("/api/v1/platform/users/{$admin->public_id}/block")->assertStatus(422);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/platform/users/{$owner->public_id}/reset-two-factor")->assertForbidden();
    }
}
