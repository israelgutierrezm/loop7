<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_login_correcto_devuelve_al_usuario(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'Password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'Password123',
        ]);

        $response->assertOk()->assertJsonPath('data.email', 'user@example.com');
        $this->assertAuthenticated();
    }

    public function test_login_con_password_incorrecta_falla(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'Password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'incorrecta',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_login_con_mfa_activo_exige_codigo(): void
    {
        User::factory()->create([
            'email' => 'mfa@example.com',
            'password' => 'Password123',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa@example.com',
            'password' => 'Password123',
        ]);

        $response->assertStatus(423)->assertJsonPath('code', 'mfa_required');
        $this->assertGuest();
    }

    public function test_logout_cierra_sesion(): void
    {
        $user = User::factory()->create();
        $this->actingInOrganization($user);

        $this->postJson('/api/v1/auth/logout')->assertOk();
    }
}
