<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cerrar_las_demas_sesiones_rehace_el_hash_y_queda_auditado(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $user = User::factory()->create(['password' => 'Secreta-123']);
        $headers = ['Referer' => 'http://localhost'];
        $previousHash = $user->password;

        $this->withHeaders($headers)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secreta-123'])
            ->assertOk();

        $this->withHeaders($headers)->postJson('/api/v1/me/sessions/logout-others', ['password' => 'mala'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->withHeaders($headers)->postJson('/api/v1/me/sessions/logout-others', ['password' => 'Secreta-123'])
            ->assertOk();

        // Nuevo hash (las sesiones con el anterior caen) de la misma contraseña.
        $user->refresh();
        $this->assertNotSame($previousHash, $user->password);
        $this->assertTrue(Hash::check('Secreta-123', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.other_sessions_revoked']);

        // La sesión actual sigue abierta.
        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
    }
}
