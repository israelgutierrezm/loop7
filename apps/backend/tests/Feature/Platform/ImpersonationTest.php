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

    public function test_no_registra_el_navegador_ni_el_whatsapp_del_administrador(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$target] = $this->createOwnerWithOrganization();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/navegador-del-admin';

        $requests = [
            ['POST', '/api/v1/me/push-subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'x', 'auth' => 'y']]],
            ['POST', '/api/v1/me/push-subscriptions/test', []],
            ['DELETE', '/api/v1/me/push-subscriptions', ['endpoint' => $endpoint]],
            ['POST', '/api/v1/me/whatsapp', ['phone' => '+5215512345678']],
            ['POST', '/api/v1/me/whatsapp/verify', ['code' => '123456']],
            ['DELETE', '/api/v1/me/whatsapp', []],
        ];
        foreach ($requests as [$method, $uri, $data]) {
            $this->impersonating($admin, $target)
                ->json($method, $uri, $data)
                ->assertForbidden()
                ->assertJsonPath('code', 'impersonation_blocked');
        }

        // Las preferencias sí puede ajustarlas (soporte).
        $this->impersonating($admin, $target)
            ->putJson('/api/v1/me/notification-preferences', ['mail' => ['billing' => false]])
            ->assertOk();
    }

    public function test_no_cambia_el_inicio_de_sesion_unico_de_la_organizacion(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$target, $org] = $this->createOwnerWithOrganization();

        $requests = [
            ['PUT', '/api/v1/organization/sso', ['is_enabled' => false, 'enforced' => false, 'jit_provisioning' => false, 'default_role' => 'VIEWER']],
            ['POST', '/api/v1/organization/sso/domains', ['domain' => 'empresa.com']],
            ['POST', '/api/v1/organization/sso/domains/01ARZ3NDEKTSV4RRFFQ69G5FAV/verify', []],
            ['DELETE', '/api/v1/organization/sso/domains/01ARZ3NDEKTSV4RRFFQ69G5FAV', []],
            ['POST', '/api/v1/organization/sso/test', []],
            ['POST', '/api/v1/organization/sso/metadata', ['xml' => '<x/>']],
        ];
        foreach ($requests as [$method, $uri, $data]) {
            $this->impersonating($admin, $target)
                ->withHeader('X-Organization', $org->public_id)
                ->json($method, $uri, $data)
                ->assertForbidden()
                ->assertJsonPath('code', 'impersonation_blocked');
        }

        // Consultarla sí (soporte).
        $this->impersonating($admin, $target)
            ->withHeader('X-Organization', $org->public_id)
            ->getJson('/api/v1/organization/sso')
            ->assertOk();
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
