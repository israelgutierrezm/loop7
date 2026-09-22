<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Api\Services\ApiKeyService;
use App\Modules\Api\Support\ApiScope;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: Organization, 1: string}  [org, plainKey]
     */
    private function orgWithKey(array $scopes): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional'); // feature.api = true
        $result = app(ApiKeyService::class)->generate($org, 'Test key', $scopes, null, $owner);

        return [$org, $result['plain']];
    }

    // --- Gestión de keys (panel) ---

    public function test_owner_crea_api_key_y_ve_el_secreto_una_vez(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional');

        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Integración CRM',
                'scopes' => ['brands:read', 'content:read'],
            ])
            ->assertStatus(201);

        $plain = $response->json('data.key');
        $this->assertNotEmpty($plain);
        $this->assertStringStartsWith('l7_', $plain);
        // En BD sólo el hash, nunca el valor en claro.
        $this->assertDatabaseMissing('api_keys', ['token_hash' => $plain]);
    }

    public function test_plan_sin_api_devuelve_402(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization(); // Growth: sin feature.api

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/api-keys')
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.api');
    }

    public function test_manager_no_gestiona_keys(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional');
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);

        $this->actingInOrganization($manager, $org)
            ->getJson('/api/v1/api-keys')
            ->assertForbidden();
    }

    // --- API pública (key auth) ---

    public function test_me_autentica_con_api_key(): void
    {
        [$org, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);

        $this->withToken($key)
            ->getJson('/api/public/v1/me')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $org->public_id)
            ->assertJsonPath('data.key.scopes.0', 'brands:read');
    }

    public function test_sin_api_key_devuelve_401(): void
    {
        $this->getJson('/api/public/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_scope_insuficiente_devuelve_403(): void
    {
        [$org, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->withToken($key)
            ->getJson("/api/public/v1/brands/{$brand->public_id}/content")
            ->assertStatus(403)
            ->assertJsonPath('code', 'insufficient_scope');
    }

    public function test_lista_marcas_con_scope(): void
    {
        [$org, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);
        Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Marca API']);

        $this->withToken($key)
            ->getJson('/api/public/v1/brands')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Marca API');
    }

    public function test_crea_contenido_con_scope_write(): void
    {
        [$org, $key] = $this->orgWithKey([ApiScope::CONTENT_WRITE]);
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->withToken($key)
            ->postJson("/api/public/v1/brands/{$brand->public_id}/content", [
                'title' => 'Post desde API',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('content_items', ['brand_id' => $brand->id, 'title' => 'Post desde API']);
    }

    public function test_aislamiento_entre_organizaciones(): void
    {
        [, $keyA] = $this->orgWithKey([ApiScope::CONTENT_READ]);
        [$orgB] = $this->orgWithKey([ApiScope::CONTENT_READ]);
        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);

        // La key A no puede leer contenido de una marca de la organización B.
        $this->withToken($keyA)
            ->getJson("/api/public/v1/brands/{$brandB->public_id}/content")
            ->assertStatus(404);
    }

    public function test_key_revocada_no_autentica(): void
    {
        [$org, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);
        \App\Modules\Api\Models\ApiKey::query()->withoutGlobalScopes()->where('organization_id', $org->id)->update(['is_active' => false]);

        $this->withToken($key)
            ->getJson('/api/public/v1/me')
            ->assertStatus(401);
    }

    // --- MCP ---

    public function test_mcp_initialize(): void
    {
        [, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);

        $this->withToken($key)
            ->postJson('/api/public/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'loop7-mcp');
    }

    public function test_mcp_tools_list_filtra_por_scope(): void
    {
        [, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);

        $response = $this->withToken($key)
            ->postJson('/api/public/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk();

        $names = collect($response->json('result.tools'))->pluck('name');
        $this->assertContains('list_brands', $names);
        $this->assertNotContains('create_content', $names); // sin scope content:write
    }

    public function test_mcp_tools_call_list_brands(): void
    {
        [$org, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);
        Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Marca MCP']);

        $response = $this->withToken($key)
            ->postJson('/api/public/v1/mcp', [
                'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => 'list_brands', 'arguments' => []],
            ])
            ->assertOk();

        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('Marca MCP', $text);
    }

    public function test_mcp_call_sin_scope_devuelve_error(): void
    {
        [$org, $key] = $this->orgWithKey([ApiScope::BRANDS_READ]);
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->withToken($key)
            ->postJson('/api/public/v1/mcp', [
                'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
                'params' => ['name' => 'create_content', 'arguments' => ['brand' => $brand->public_id, 'title' => 'x']],
            ])
            ->assertOk()
            ->assertJsonPath('error.code', -32001);
    }
}
