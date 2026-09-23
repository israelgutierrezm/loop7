<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_reconectar_la_misma_cuenta_actualiza_sin_duplicar(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        foreach (['primer-codigo', 'segundo-codigo'] as $code) {
            $url = $this->actingInOrganization($owner, $org)
                ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/authorize")
                ->json('data.authorize_url');
            $this->get("/api/v1/social/callback/fake?code={$code}&state={$this->stateFromUrl($url)}")->assertRedirect();
        }

        $this->assertDatabaseCount('social_connections', 1);
        $this->assertDatabaseHas('social_connections', ['external_account_id' => 'fake-account', 'status' => 'connected']);
        $this->assertDatabaseCount('social_connection_destinations', 2);
        $this->assertDatabaseHas('social_token_events', ['event' => 'reconnected']);
        $this->assertSame(
            'fake-access-segundo-codigo',
            SocialConnection::query()->withoutGlobalScopes()->firstOrFail()->access_token,
        );
    }

    public function test_reconectar_reactiva_una_conexion_expirada(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'expired', 'external_account_id' => 'fake-account', 'external_account_name' => 'Demo',
        ]);

        $url = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/authorize")
            ->json('data.authorize_url');
        $this->get("/api/v1/social/callback/fake?code=nuevo&state={$this->stateFromUrl($url)}")->assertRedirect();

        $this->assertDatabaseCount('social_connections', 1);
        $this->assertDatabaseHas('social_connections', ['external_account_id' => 'fake-account', 'status' => 'connected']);
    }

    public function test_el_comando_marca_expiradas_las_conexiones_sin_renovacion(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $caducada = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'A', 'access_token' => 'x',
            'token_expires_at' => now()->subHour(),
        ]);
        $renovable = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'B', 'access_token' => 'y',
            'refresh_token' => 'fake-refresh', 'token_expires_at' => now()->addMinutes(30),
        ]);

        $this->artisan('social:refresh-tokens')->assertSuccessful();

        $this->assertSame('expired', $caducada->fresh()->status->value);
        $this->assertSame('connected', $renovable->fresh()->status->value);
        $this->assertSame('fake-access-refreshed', $renovable->fresh()->access_token);
    }

    public function test_instagram_usa_las_credenciales_de_la_app_de_facebook(): void
    {
        $facebook = SocialProvider::query()->where('key', 'facebook')->firstOrFail();
        $facebook->credentials = ['client_id' => 'META_APP', 'client_secret' => 'META_SECRET'];
        $facebook->config = ['graph_version' => 'v26.0'];
        $facebook->save();

        $credentials = app(SocialProviderManager::class)->credentials('instagram');

        $this->assertSame('META_APP', $credentials['client_id']);
        $this->assertSame('META_SECRET', $credentials['client_secret']);
        $this->assertSame('v26.0', $credentials['graph_version']);
    }

    public function test_superadmin_prueba_la_conexion_del_proveedor(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        // Sin credenciales: resultado negativo con mensaje claro.
        $this->postJson('/api/v1/platform/social-providers/facebook/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false);

        $facebook = SocialProvider::query()->where('key', 'facebook')->firstOrFail();
        $facebook->credentials = ['client_id' => 'META_APP', 'client_secret' => 'META_SECRET'];
        $facebook->save();
        Http::fake(['*' => Http::response(['access_token' => 'APP|TOKEN'])]);

        $this->postJson('/api/v1/platform/social-providers/facebook/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_superadmin_ajusta_version_de_graph_y_ve_las_urls_de_la_app(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->putJson('/api/v1/platform/social-providers/facebook', ['graph_version' => 'v26.0'])
            ->assertOk()
            ->assertJsonPath('data.graph_version', 'v26.0')
            ->assertJsonPath('data.setup.redirect_uri', url('/api/v1/social/callback/facebook'));

        $this->putJson('/api/v1/platform/social-providers/facebook', ['graph_version' => '26'])
            ->assertStatus(422);

        // Sólo se listan redes con adaptador implementado.
        $keys = collect($this->getJson('/api/v1/platform/social-providers')->assertOk()->json('data'))->pluck('key');
        $this->assertEqualsCanonicalizing(['fake', 'facebook', 'instagram'], $keys->all());
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
