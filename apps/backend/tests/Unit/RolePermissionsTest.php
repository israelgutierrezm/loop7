<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\AccessControl\Permissions\RolePermissions;
use PHPUnit\Framework\TestCase;

/**
 * Valida el modelo de permisos por rol (criterios de aceptación docs/15).
 */
class RolePermissionsTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    private function map(): array
    {
        return RolePermissions::map();
    }

    public function test_owner_tiene_todos_los_permisos(): void
    {
        $owner = $this->map()[OrganizationRole::OWNER->value];

        $this->assertEqualsCanonicalizing(Permission::all(), $owner);
    }

    public function test_admin_no_puede_eliminar_ni_transferir_la_organizacion(): void
    {
        $admin = $this->map()[OrganizationRole::ADMIN->value];

        $this->assertNotContains(Permission::ORGANIZATION_DELETE, $admin);
        $this->assertNotContains(Permission::ORGANIZATION_TRANSFER_OWNERSHIP, $admin);
        $this->assertContains(Permission::BRANDS_CREATE, $admin);
    }

    public function test_content_creator_crea_pero_no_publica_ni_aprueba(): void
    {
        $creator = $this->map()[OrganizationRole::CONTENT_CREATOR->value];

        $this->assertContains(Permission::CONTENT_CREATE, $creator);
        $this->assertContains(Permission::CONTENT_SUBMIT_FOR_REVIEW, $creator);
        $this->assertNotContains(Permission::CONTENT_PUBLISH_NOW, $creator);
        $this->assertNotContains(Permission::CONTENT_APPROVE, $creator);
    }

    public function test_approver_aprueba_pero_no_gestiona_oauth(): void
    {
        $approver = $this->map()[OrganizationRole::APPROVER->value];

        $this->assertContains(Permission::CONTENT_APPROVE, $approver);
        $this->assertContains(Permission::CONTENT_REJECT, $approver);
        $this->assertNotContains(Permission::SOCIAL_ACCOUNTS_CONNECT, $approver);
        $this->assertNotContains(Permission::CONTENT_CREATE, $approver);
    }

    public function test_publisher_puede_publicar(): void
    {
        $publisher = $this->map()[OrganizationRole::PUBLISHER->value];

        $this->assertContains(Permission::CONTENT_PUBLISH_NOW, $publisher);
        $this->assertContains(Permission::CONTENT_SCHEDULE, $publisher);
    }

    public function test_billing_no_accede_a_contenido(): void
    {
        $billing = $this->map()[OrganizationRole::BILLING->value];

        $this->assertContains(Permission::BILLING_VIEW, $billing);
        $this->assertNotContains(Permission::CONTENT_VIEW, $billing);
    }

    public function test_viewer_es_solo_lectura(): void
    {
        $viewer = $this->map()[OrganizationRole::VIEWER->value];

        foreach ($viewer as $permission) {
            $this->assertStringContainsString(
                '.view',
                $permission,
                "El VIEWER sólo debería tener permisos de lectura; encontrado: {$permission}",
            );
        }

        $this->assertNotContains(Permission::BRANDS_CREATE, $viewer);
        $this->assertNotContains(Permission::CONTENT_CREATE, $viewer);
    }
}
