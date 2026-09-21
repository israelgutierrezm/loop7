<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function stateFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    public function test_flujo_oauth_completo_con_provider_fake(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        // 1) Autorización: devuelve URL con state.
        $authorize = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/authorize")
            ->assertOk();

        $url = $authorize->json('data.authorize_url');
        $state = $this->stateFromUrl($url);
        $this->assertNotEmpty($state);

        // 2) Callback: crea la conexión y sus destinos, redirige al SPA.
        $this->get("/api/v1/social/callback/fake?code=test-code&state={$state}")
            ->assertRedirect();

        $this->assertDatabaseHas('social_connections', [
            'brand_id' => $brand->id,
            'provider' => 'fake',
            'status' => 'connected',
        ]);
        $this->assertDatabaseCount('social_connection_destinations', 2);

        // 3) El listado no expone tokens.
        $list = $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/social/connections")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('access_token', $list->getContent());
    }

    public function test_el_state_es_de_un_solo_uso(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $url = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/authorize")
            ->json('data.authorize_url');
        $state = $this->stateFromUrl($url);

        $this->get("/api/v1/social/callback/fake?code=c1&state={$state}")
            ->assertRedirect();

        // Reutilizar el mismo state debe fallar (redirige con social=invalid).
        $response = $this->get("/api/v1/social/callback/fake?code=c2&state={$state}");
        $response->assertRedirect();
        $this->assertStringContainsString('social=invalid', $response->headers->get('Location'));

        $this->assertDatabaseCount('social_connections', 1);
    }

    public function test_los_tokens_se_guardan_cifrados(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $url = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/authorize")
            ->json('data.authorize_url');
        $state = $this->stateFromUrl($url);
        $this->get("/api/v1/social/callback/fake?code=secret-code&state={$state}")->assertRedirect();

        $stored = DB::table('social_connections')->value('access_token');
        $this->assertNotEmpty($stored);
        // El valor cifrado no contiene el token en claro.
        $this->assertStringNotContainsString('fake-access-secret-code', (string) $stored);
    }

    public function test_no_se_conecta_un_proveedor_deshabilitado(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        // facebook está deshabilitado por defecto.
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/facebook/authorize")
            ->assertStatus(422);
    }

    public function test_aislamiento_no_conecta_marca_de_otra_organizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');
        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrganization($ownerA, $orgA)
            ->postJson("/api/v1/brands/{$brandB->public_id}/social/connections/fake/authorize")
            ->assertNotFound();
    }

    public function test_superadmin_configura_proveedor_y_credenciales_cifradas(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->putJson('/api/v1/platform/social-providers/facebook', ['is_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true);

        $response = $this->putJson('/api/v1/platform/social-providers/facebook/credentials', [
            'credentials' => ['client_id' => 'app-123', 'client_secret' => 'super-secret-xyz'],
        ])->assertOk();

        // La respuesta no expone el secreto.
        $this->assertStringNotContainsString('super-secret-xyz', $response->getContent());

        // En BD las credenciales están cifradas.
        $stored = DB::table('social_providers')->where('key', 'facebook')->value('credentials');
        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString('super-secret-xyz', (string) $stored);
    }
}
