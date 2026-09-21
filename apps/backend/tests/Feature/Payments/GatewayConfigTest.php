<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatewayConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_el_cliente_solo_ve_pasarelas_habilitadas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        $response = $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/billing/gateways')
            ->assertOk();

        $keys = collect($response->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('manual'));
        $this->assertFalse($keys->contains('stripe'));
    }

    public function test_superadmin_puede_habilitar_una_pasarela(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->putJson('/api/v1/platform/payment-gateways/stripe', ['is_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true);

        $this->assertDatabaseHas('payment_gateways', ['key' => 'stripe', 'is_enabled' => true]);
    }

    public function test_las_credenciales_se_guardan_cifradas_y_se_devuelven_enmascaradas(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $secret = 'sk_test_ABC123456789';

        $response = $this->putJson('/api/v1/platform/payment-gateways/stripe/credentials', [
            'environment' => 'test',
            'credentials' => ['secret_key' => $secret],
        ])->assertOk();

        // La respuesta nunca expone el valor en claro.
        $body = $response->getContent();
        $this->assertStringNotContainsString($secret, $body);

        // En base de datos el valor está cifrado (no coincide con el texto plano).
        $stored = DB::table('payment_gateway_credentials')
            ->where('key', 'secret_key')
            ->where('environment', 'test')
            ->value('value');
        $this->assertNotSame($secret, $stored);
        $this->assertNotEmpty($stored);
    }

    public function test_credenciales_de_test_y_produccion_estan_separadas(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->putJson('/api/v1/platform/payment-gateways/stripe/credentials', [
            'environment' => 'test',
            'credentials' => ['secret_key' => 'sk_test_111'],
        ])->assertOk();

        $this->putJson('/api/v1/platform/payment-gateways/stripe/credentials', [
            'environment' => 'production',
            'credentials' => ['secret_key' => 'sk_live_999'],
        ])->assertOk();

        $this->assertDatabaseCount('payment_gateway_credentials', 2);
        $environments = DB::table('payment_gateway_credentials')->pluck('environment')->sort()->values()->all();
        $this->assertSame(['production', 'test'], $environments);
    }
}
