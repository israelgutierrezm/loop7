<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Gestión del equipo sin escaladas: quién asigna qué rol y el acceso por marca.
 */
class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Notification::fake();
    }

    public function test_un_manager_no_crea_administradores(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);
        $admin = $this->addMember($org, OrganizationRole::ADMIN->value);
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);
        $as = fn () => $this->actingInOrganization($manager, $org);

        $as()->getJson('/api/v1/roles')->assertOk()->assertJsonMissing(['assignable' => ['ADMIN']]);
        $this->assertNotContains('ADMIN', $as()->getJson('/api/v1/roles')->json('data.assignable'));

        $as()->postJson('/api/v1/organization/invitations', ['email' => 'nuevo@x.test', 'role' => 'ADMIN'])
            ->assertStatus(422)->assertJsonValidationErrors(['role']);
        $as()->postJson('/api/v1/organization/invitations', ['email' => 'nuevo@x.test', 'role' => 'PUBLISHER'])
            ->assertCreated();

        // Puede cambiar roles "de su nivel hacia abajo" (antes el endpoint se lo impedía)…
        $as()->patchJson("/api/v1/organization/members/{$viewer->public_id}", ['role' => 'PUBLISHER'])
            ->assertOk()->assertJsonPath('data.roles', ['PUBLISHER']);
        // …pero no nombrar ADMIN ni tocar a un ADMIN.
        $as()->patchJson("/api/v1/organization/members/{$viewer->public_id}", ['role' => 'ADMIN'])->assertForbidden();
        $as()->patchJson("/api/v1/organization/members/{$admin->public_id}", ['role' => 'VIEWER'])->assertForbidden();
    }

    public function test_nadie_cambia_su_propio_rol_ni_su_acceso(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $admin = $this->addMember($org, OrganizationRole::ADMIN->value, allBrandsAccess: false);

        $this->actingInOrganization($admin, $org)
            ->patchJson("/api/v1/organization/members/{$admin->public_id}", ['all_brands_access' => true])
            ->assertStatus(422);
        $this->actingInOrganization($admin, $org)
            ->patchJson("/api/v1/organization/members/{$admin->public_id}", ['role' => 'OWNER'])
            ->assertStatus(422);
    }

    public function test_limitar_un_miembro_a_ciertas_marcas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $a = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'A']);
        $b = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'B']);
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);

        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/organization/members/{$creator->public_id}", ['all_brands_access' => false, 'brands' => [$a->public_id]])
            ->assertOk()
            ->assertJsonPath('data.membership.all_brands_access', false)
            ->assertJsonPath('data.membership.brands', [$a->public_id]);

        $brands = collect($this->actingInOrganization($creator, $org)->getJson('/api/v1/context')->json('data.brands'))->pluck('id')->all();
        $this->assertSame([$a->public_id], $brands);

        // Cambiar la selección revoca lo que ya no está.
        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/organization/members/{$creator->public_id}", ['brands' => [$b->public_id]])
            ->assertJsonPath('data.membership.brands', [$b->public_id]);
    }

    public function test_un_gestor_limitado_solo_gestiona_sus_marcas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $a = Brand::factory()->create(['organization_id' => $org->id]);
        $b = Brand::factory()->create(['organization_id' => $org->id]);
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value, allBrandsAccess: false);
        $a->grantAccessTo($manager->id);
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value, allBrandsAccess: false);
        $b->grantAccessTo($creator->id); // concedida por el owner

        $as = fn () => $this->actingInOrganization($manager, $org);

        $as()->patchJson("/api/v1/organization/members/{$creator->public_id}", ['all_brands_access' => true])->assertForbidden();

        // Pide A y B: sólo puede conceder A; B (ajena a él) no se toca.
        $as()->patchJson("/api/v1/organization/members/{$creator->public_id}", ['brands' => [$a->public_id, $b->public_id]])
            ->assertOk();
        $granted = DB::table('brand_user_access')->where('user_id', $creator->id)->pluck('brand_id')->sort()->values()->all();
        $this->assertSame(collect([$a->id, $b->id])->sort()->values()->all(), $granted);

        // Y al "quitar todo" sólo quita lo que gestiona.
        $as()->patchJson("/api/v1/organization/members/{$creator->public_id}", ['brands' => []])->assertOk();
        $this->assertSame([$b->id], DB::table('brand_user_access')->where('user_id', $creator->id)->pluck('brand_id')->all());
    }

    public function test_eliminar_un_miembro_limpia_su_acceso_a_marcas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $member = $this->addMember($org, OrganizationRole::VIEWER->value, allBrandsAccess: false);
        $brand->grantAccessTo($member->id);

        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/organization/members/{$member->public_id}")->assertOk();

        $this->assertDatabaseMissing('brand_user_access', ['user_id' => $member->id]);
    }

    public function test_suspender_un_miembro_le_quita_el_acceso_sin_quitarlo(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $member = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $admin = $this->addMember($org, OrganizationRole::ADMIN->value);
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);

        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/organization/members/{$member->public_id}", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.membership.status', 'suspended');
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.suspended']);

        // Sin acceso a la organización, pero conserva su rol para cuando vuelva.
        $this->actingInOrganization($member, $org)->getJson('/api/v1/context')
            ->assertForbidden()->assertJsonPath('code', 'organization_not_resolved');
        $this->assertDatabaseHas('organization_user', ['user_id' => $member->id, 'status' => 'suspended']);

        // Un MANAGER no suspende a un ADMIN; nadie se suspende a sí mismo ni al propietario.
        $this->actingInOrganization($manager, $org)
            ->patchJson("/api/v1/organization/members/{$admin->public_id}", ['status' => 'suspended'])->assertForbidden();
        $this->actingInOrganization($admin, $org)
            ->patchJson("/api/v1/organization/members/{$admin->public_id}", ['status' => 'suspended'])->assertStatus(422);
        $this->actingInOrganization($admin, $org)
            ->patchJson("/api/v1/organization/members/{$owner->public_id}", ['status' => 'suspended'])->assertStatus(422);

        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/organization/members/{$member->public_id}", ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.reactivated']);
        $this->actingInOrganization($member->fresh(), $org)->getJson('/api/v1/context')->assertOk();
    }
}
