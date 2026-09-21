<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_flujo_de_activacion_de_mfa(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);
        $this->actingInOrganization($user);

        $enable = $this->postJson('/api/v1/me/two-factor/enable')->assertOk();
        $secret = $enable->json('data.secret');

        $this->assertNotEmpty($secret);
        $this->assertNotEmpty($enable->json('data.recovery_codes'));
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        $otp = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/api/v1/me/two-factor/confirm', ['code' => $otp])->assertOk();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'mfa.enabled']);
    }

    public function test_login_con_codigo_mfa_valido(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        User::factory()->create([
            'email' => 'mfa@example.com',
            'password' => 'Password123',
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        $otp = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa@example.com',
            'password' => 'Password123',
            'code' => $otp,
        ])->assertOk()->assertJsonPath('data.email', 'mfa@example.com');
    }
}
