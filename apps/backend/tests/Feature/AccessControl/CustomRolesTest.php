<?php

declare(strict_types=1);

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function professionalOrg(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional'); // incluye roles personalizados

        return [$owner, $org];
    }

    public function test_crear_asignar_y_usar_un_rol_personalizado(): void
    {
        [$owner, $org] = $this->professionalOrg();
        $member = $this->addMember($org, OrganizationRole::VIEWER->value);

        $role = $this->actingInOrganization($owner, $org)->postJson('/api/v1/roles', [
            'label' => 'Community manager',
            'description' => 'Responde el inbox y programa.',
            'permissions' => ['content.view', 'content.schedule', 'social_accounts.inbox', 'brands.view'],
        ])->assertCreated()->assertJsonPath('data.custom', true)->json('data.value');

        $this->assertStringStartsWith('custom_', $role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.created']);

        // Aparece en el catálogo y es asignable por el propietario.
        $catalog = $this->actingInOrganization($owner, $org)->getJson('/api/v1/roles')->assertOk();
        $this->assertContains($role, $catalog->json('data.assignable'));
        $this->assertTrue($catalog->json('data.custom_roles_available'));

        // Asignado a un miembro, le da exactamente esos permisos.
        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/organization/members/{$member->public_id}", ['role' => $role])
            ->assertOk()->assertJsonPath('data.roles', [$role]);

        $permissions = $this->actingInOrganization($member->fresh(), $org)->getJson('/api/v1/context')->json('data.permissions');
        $this->assertEqualsCanonicalizing(['content.view', 'content.schedule', 'social_accounts.inbox', 'brands.view'], $permissions);

        // /me muestra su nombre visible, no el interno.
        $me = $this->actingInOrganization($member->fresh(), $org)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['Community manager'], $me->json('data.organizations.0.role_labels'));
    }

    public function test_editar_cambia_los_permisos_de_quien_lo_tiene_y_queda_auditado(): void
    {
        [$owner, $org] = $this->professionalOrg();
        $member = $this->addMember($org, OrganizationRole::VIEWER->value);
        $role = $this->actingInOrganization($owner, $org)->postJson('/api/v1/roles', [
            'label' => 'Revisor', 'permissions' => ['content.view'],
        ])->json('data.value');
        $this->actingInOrganization($owner, $org)->patchJson("/api/v1/organization/members/{$member->public_id}", ['role' => $role])->assertOk();

        $this->actingInOrganization($owner, $org)->patchJson("/api/v1/roles/{$role}", [
            'label' => 'Revisor senior', 'permissions' => ['content.view', 'content.approve'],
        ])->assertOk()->assertJsonPath('data.label', 'Revisor senior');

        $this->assertContains('content.approve', $this->actingInOrganization($member->fresh(), $org)->getJson('/api/v1/context')->json('data.permissions'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.updated']);
    }

    public function test_nadie_concede_lo_que_no_tiene_ni_lo_reservado_al_propietario(): void
    {
        [$owner, $org] = $this->professionalOrg();
        $admin = $this->addMember($org, OrganizationRole::ADMIN->value);
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/roles', ['label' => 'Casi dueño', 'permissions' => ['organization.delete']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');

        // El MANAGER no tiene roles.create.
        $this->actingInOrganization($manager, $org)
            ->postJson('/api/v1/roles', ['label' => 'X', 'permissions' => ['content.view']])
            ->assertForbidden();

        // Un rol creado por el propietario con facturación: el MANAGER no puede asignarlo.
        $billingLike = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/roles', ['label' => 'Finanzas', 'permissions' => ['billing.view', 'billing.change_plan']])
            ->json('data.value');
        $this->assertNotContains($billingLike, $this->actingInOrganization($manager, $org)->getJson('/api/v1/roles')->json('data.assignable'));
        $this->assertNotContains('BILLING', $this->actingInOrganization($manager, $org)->getJson('/api/v1/roles')->json('data.assignable'));
        $this->assertContains($billingLike, $this->actingInOrganization($admin, $org)->getJson('/api/v1/roles')->json('data.assignable'));
    }

    public function test_nombres_unicos_y_distintos_de_los_predefinidos(): void
    {
        [$owner, $org] = $this->professionalOrg();
        $api = $this->actingInOrganization($owner, $org);

        $api->postJson('/api/v1/roles', ['label' => 'Aprobador', 'permissions' => ['content.view']])
            ->assertStatus(422)->assertJsonValidationErrors('label');
        $api->postJson('/api/v1/roles', ['label' => 'Editor', 'permissions' => ['content.view']])->assertCreated();
        $api->postJson('/api/v1/roles', ['label' => ' editor ', 'permissions' => ['content.view']])
            ->assertStatus(422)->assertJsonValidationErrors('label');
    }

    public function test_no_se_elimina_un_rol_en_uso_y_si_libre(): void
    {
        [$owner, $org] = $this->professionalOrg();
        $member = $this->addMember($org, OrganizationRole::VIEWER->value);
        $api = fn () => $this->actingInOrganization($owner, $org);
        $role = $api()->postJson('/api/v1/roles', ['label' => 'Temporal', 'permissions' => ['content.view']])->json('data.value');
        $api()->patchJson("/api/v1/organization/members/{$member->public_id}", ['role' => $role])->assertOk();

        $api()->deleteJson("/api/v1/roles/{$role}")->assertStatus(409);

        $api()->patchJson("/api/v1/organization/members/{$member->public_id}", ['role' => 'VIEWER'])->assertOk();
        $api()->deleteJson("/api/v1/roles/{$role}")->assertOk();
        $this->assertDatabaseMissing('roles', ['name' => $role]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted']);
    }

    public function test_invitar_con_un_rol_personalizado(): void
    {
        [$owner, $org] = $this->professionalOrg();
        $role = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/roles', ['label' => 'Diseño', 'permissions' => ['content.view', 'content.create']])
            ->json('data.value');

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/invitations', ['email' => 'disena@x.test', 'role' => $role])
            ->assertCreated();

        // Mientras la invitación está pendiente no se puede eliminar el rol.
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/roles/{$role}")->assertStatus(409);
    }

    public function test_aislamiento_y_plan(): void
    {
        [$ownerA, $orgA] = $this->professionalOrg();
        [$ownerB, $orgB] = $this->createOwnerWithOrganization([], 'Otra org');
        $roleA = $this->actingInOrganization($ownerA, $orgA)
            ->postJson('/api/v1/roles', ['label' => 'Solo A', 'permissions' => ['content.view']])
            ->json('data.value');

        // Otra organización no ve, edita, elimina ni asigna el rol de A.
        $this->assertNotContains($roleA, $this->actingInOrganization($ownerB, $orgB)->getJson('/api/v1/roles')->json('data.assignable'));
        $this->actingInOrganization($ownerB, $orgB)->deleteJson("/api/v1/roles/{$roleA}")->assertNotFound();
        $memberB = $this->addMember($orgB, OrganizationRole::VIEWER->value);
        $this->actingInOrganization($ownerB, $orgB)
            ->patchJson("/api/v1/organization/members/{$memberB->public_id}", ['role' => $roleA])
            ->assertStatus(422);

        // Sin la función en su plan (Growth), B no puede crear roles.
        $this->actingInOrganization($ownerB, $orgB)
            ->postJson('/api/v1/roles', ['label' => 'Rol de B', 'permissions' => ['content.view']])
            ->assertStatus(402);
    }

    public function test_nadie_modifica_el_rol_que_tiene(): void
    {
        [$owner, $org] = $this->professionalOrg();
        $role = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/roles', ['label' => 'Gestor de roles', 'permissions' => ['roles.view', 'roles.update', 'content.view']])
            ->json('data.value');
        $member = $this->addMember($org, OrganizationRole::VIEWER->value);
        $this->actingInOrganization($owner, $org)->patchJson("/api/v1/organization/members/{$member->public_id}", ['role' => $role])->assertOk();

        $this->actingInOrganization($member->fresh(), $org)
            ->patchJson("/api/v1/roles/{$role}", ['label' => 'Gestor de roles', 'permissions' => ['roles.view', 'roles.update', 'content.view']])
            ->assertForbidden();
    }
}
