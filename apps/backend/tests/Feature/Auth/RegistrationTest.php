<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_el_registro_crea_usuario_organizacion_y_rol_owner(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'organization_name' => 'Analytical Engines',
            'accept_terms' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.is_platform_admin', false);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertDatabaseHas('organizations', ['name' => 'Analytical Engines']);

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $organization = $user->ownedOrganizations()->firstOrFail();

        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $this->assertTrue($user->hasRole('OWNER'));

        // El registro auditó la creación de la Organization.
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.created']);
    }

    public function test_la_respuesta_no_expone_secretos(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'accept_terms' => true,
        ]);

        $response->assertCreated()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.two_factor_secret')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_requiere_aceptar_terminos(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sin Terminos',
            'email' => 'noterms@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'accept_terms' => false,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'validation_error');
        $this->assertDatabaseMissing('users', ['email' => 'noterms@example.com']);
    }

    public function test_rechaza_correo_duplicado(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicado',
            'email' => 'dup@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'accept_terms' => true,
        ]);

        $response->assertStatus(422);
    }
}
