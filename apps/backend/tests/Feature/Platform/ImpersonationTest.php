<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Límites de la impersonación (docs/09): acciones críticas bloqueadas,
 * atribución del administrador en la auditoría y caducidad a los 60 minutos.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        // Peticiones "del SPA" (con sesión) desde este origen.
        config(['sanctum.stateful' => ['localhost']]);
    }

    /**
     * Petición del SPA (con sesión) de un administrador que impersona a $target.
     */
    private function impersonating(User $admin, User $target, int $minutesAgo = 0): static
    {
        Sanctum::actingAs($target);

        return $this->withHeader('Referer', 'http://localhost')->withSession([
            'impersonator_id' => $admin->id,
            'impersonation_started_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    public function test_acciones_criticas_bloqueadas_y_auditoria_atribuida(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$target, $org] = $this->createOwnerWithOrganization();

        $this->impersonating($admin, $target)
            ->putJson('/api/v1/me/password', ['current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'])
            ->assertForbidden()
            ->assertJsonPath('code', 'impersonation_blocked');

        $this->impersonating($admin, $target)
            ->withHeader('X-Organization', $org->public_id)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter', 'interval' => 'month', 'gateway' => 'manual'])
            ->assertForbidden();

        // Lo que sí puede hacer queda atribuido al administrador en la auditoría.
        $this->impersonating($admin, $target)
            ->withHeader('X-Organization', $org->public_id)
            ->patchJson('/api/v1/organization', ['name' => 'Soporte cambió el nombre'])
            ->assertOk();

        $properties = json_decode((string) DB::table('audit_logs')->where('action', 'organization.updated')->value('properties'), true);
        $this->assertSame($admin->public_id, $properties['impersonated_by']);
    }

    public function test_la_impersonacion_caduca_a_los_60_minutos(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$target] = $this->createOwnerWithOrganization();

        $this->impersonating($admin, $target, minutesAgo: 61)
            ->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'impersonation_expired');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'superadmin.impersonation_ended',
            'user_id' => $admin->id,
        ]);
    }

    public function test_sin_impersonacion_no_se_bloquea_nada(): void
    {
        [$owner] = $this->createOwnerWithOrganization();
        Sanctum::actingAs($owner);

        $this->withHeader('Referer', 'http://localhost')
            ->putJson('/api/v1/me/password', ['current_password' => 'incorrecta', 'password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123'])
            ->assertStatus(422); // llega a la validación normal
    }
}
