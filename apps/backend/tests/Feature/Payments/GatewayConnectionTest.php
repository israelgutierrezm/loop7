<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentGatewayCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatewayConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
    }

    private function setSecret(string $gateway, string $environment, string $value): void
    {
        $record = PaymentGateway::query()->where('key', $gateway)->first();
        $record->update(['environment' => $environment]);
        PaymentGatewayCredential::query()->create([
            'payment_gateway_id' => $record->id,
            'environment' => $environment,
            'key' => 'secret_key',
            'value' => $value,
        ]);
    }

    public function test_probar_conexion_manual_es_ok(): void
    {
        $this->postJson('/api/v1/platform/payment-gateways/manual/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_probar_conexion_stripe_con_clave_valida(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['object' => 'balance'], 200)]);
        $this->setSecret('stripe', 'test', 'sk_test_valida');

        $this->postJson('/api/v1/platform/payment-gateways/stripe/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.environment', 'test');
    }

    public function test_probar_conexion_stripe_con_clave_invalida(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([], 401)]);
        $this->setSecret('stripe', 'test', 'sk_test_mala');

        $this->postJson('/api/v1/platform/payment-gateways/stripe/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false);
    }

    public function test_probar_sin_credenciales_avisa(): void
    {
        // Stripe sin credenciales configuradas en el entorno activo.
        $this->postJson('/api/v1/platform/payment-gateways/stripe/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false);
    }

    public function test_solo_superadmin_puede_probar(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/platform/payment-gateways/manual/test')->assertForbidden();
    }
}
