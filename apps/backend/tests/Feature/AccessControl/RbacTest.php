<?php

declare(strict_types=1);

namespace Tests\Feature\AccessControl;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_viewer_no_puede_crear_brand(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->postJson('/api/v1/brands', ['name' => 'Marca Nueva'])
            ->assertForbidden();
    }

    public function test_manager_puede_crear_brand(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);

        $this->actingInOrganization($manager, $org)
            ->postJson('/api/v1/brands', ['name' => 'Marca Manager'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Marca Manager');
    }

    public function test_viewer_puede_listar_pero_no_invitar(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        Brand::factory()->create(['organization_id' => $org->id]);
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingInOrganization($viewer, $org)
            ->postJson('/api/v1/organization/invitations', [
                'email' => 'nuevo@example.com',
                'role' => OrganizationRole::VIEWER->value,
            ])
            ->assertForbidden();
    }

    public function test_owner_puede_invitar_miembros(): void
    {
        Notification::fake();
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/invitations', [
                'email' => 'invitado@example.com',
                'role' => OrganizationRole::CONTENT_CREATOR->value,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $org->id,
            'email' => 'invitado@example.com',
            'role' => OrganizationRole::CONTENT_CREATOR->value,
            'status' => 'pending',
        ]);
    }

    public function test_no_se_puede_invitar_como_owner(): void
    {
        Notification::fake();
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/invitations', [
                'email' => 'otro@example.com',
                'role' => OrganizationRole::OWNER->value,
            ])
            ->assertStatus(422);
    }

    public function test_brand_access_restringido_solo_ve_sus_brands(): void
    {
        [, $org] = $this->createOwnerWithOrganization();

        $visible = Brand::factory()->create(['organization_id' => $org->id]);
        Brand::factory()->create(['organization_id' => $org->id]); // no accesible

        $restricted = $this->addMember($org, OrganizationRole::MANAGER->value, allBrandsAccess: false);
        $visible->grantAccessTo($restricted->id);

        $this->actingInOrganization($restricted, $org)
            ->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id);
    }
}
