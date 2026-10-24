<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Límites por ruta (docs/19): cada `throttle:N,M` lleva su prefijo y no comparte
 * contador con las demás; las rutas que piden la contraseña actual sí comparten
 * uno («password-confirm») contra quien intente adivinarla con una sesión robada.
 */
class RateLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_limite_no_lo_consumen_otras_rutas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        // El buscador permite 120/min: usarlo no agota los 6/min de otra ruta.
        for ($i = 0; $i < 7; $i++) {
            $this->actingInOrganization($owner, $org)->getJson('/api/v1/search?q=marca')->assertOk();
        }

        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/me/password', ['current_password' => 'no-es-la-mia', 'password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_ninguna_ruta_usa_un_limite_numerico_sin_prefijo(): void
    {
        $missing = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^throttle:\d+,\d+$/', $middleware) === 1) {
                    $missing[] = implode('|', $route->methods()) . ' ' . $route->uri() . " ({$middleware})";
                }
            }
        }

        $this->assertSame([], $missing, 'Usa throttle:N,M,prefijo: sin prefijo todas esas rutas comparten un contador.');
    }

    public function test_las_rutas_que_piden_la_contrasena_comparten_el_limite(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $wrong = ['password' => 'no-es-la-mia'];

        for ($i = 0; $i < 3; $i++) {
            $this->actingInOrganization($owner, $org)
                ->deleteJson('/api/v1/organization', $wrong + ['confirm_name' => $org->name])
                ->assertUnprocessable();
            $this->actingInOrganization($owner, $org)
                ->postJson('/api/v1/me/sessions/logout-others', $wrong)
                ->assertUnprocessable();
        }

        // Seis intentos en total: el séptimo, en otra ruta que pide la contraseña, espera.
        $this->actingInOrganization($owner, $org)
            ->deleteJson('/api/v1/me/two-factor', $wrong)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/transfer-ownership', $wrong + ['user_id' => 'x'])
            ->assertStatus(429);

        // Lo demás sigue disponible.
        $this->actingInOrganization($owner, $org)->getJson('/api/v1/search?q=marca')->assertOk();
    }
}
