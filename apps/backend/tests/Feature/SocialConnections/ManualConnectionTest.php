<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_conexion_manual_crea_conexion_con_token_cifrado_y_destinos(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $owner = $org->owner;
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/manual", [
                'external_account_name' => 'Mi Página FB',
                'external_account_id' => 'page-123',
                'access_token' => 'EAAB-token-secreto',
                'destinations' => [
                    ['external_id' => 'page-123', 'name' => 'Mi Página', 'type' => 'page'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.account_name', 'Mi Página FB')
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('social_connections', [
            'brand_id' => $brand->id, 'provider' => 'fake', 'status' => 'connected', 'external_account_id' => 'page-123',
        ]);
        $this->assertDatabaseHas('social_connection_destinations', ['external_id' => 'page-123', 'name' => 'Mi Página']);

        // El token se guarda cifrado, nunca en claro.
        $stored = DB::table('social_connections')->where('brand_id', $brand->id)->value('access_token');
        $this->assertNotSame('EAAB-token-secreto', $stored);
        $this->assertNotEmpty($stored);
    }

    public function test_rol_sin_permiso_no_puede_conectar_manualmente(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $this->actingInOrganization($creator, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/fake/manual", [
                'external_account_name' => 'X',
                'access_token' => 'token',
            ])
            ->assertForbidden();
    }

    public function test_proveedor_deshabilitado_es_rechazado(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $owner = $org->owner;
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        // 'facebook' viene deshabilitado en el seeder.
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/social/connections/facebook/manual", [
                'external_account_name' => 'X',
                'access_token' => 'token',
            ])
            ->assertStatus(422);
    }
}
