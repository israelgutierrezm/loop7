<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Modules\AccessControl\Enums\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Notification::fake();
    }

    public function test_auditoria_de_la_organizacion_con_filtros_y_solo_para_administradores(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization(['name' => 'Ana Dueña']);
        [, $other] = $this->createOwnerWithOrganization([], 'Otra');
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        // Genera eventos: invitación (member.*) y cambio de la organización.
        $this->actingInOrganization($owner, $org)->postJson('/api/v1/organization/invitations', ['email' => 'x@y.test', 'role' => 'VIEWER'])->assertCreated();
        $this->actingInOrganization($owner, $org)->patchJson('/api/v1/organization', ['name' => 'Nuevo nombre'])->assertOk();

        $all = $this->actingInOrganization($owner, $org)->getJson('/api/v1/audit-logs')->assertOk();
        $this->assertNotEmpty($all->json('data'));
        // Aislada: no aparecen eventos de otra organización.
        $this->assertNotContains($other->public_id, collect($all->json('data'))->pluck('properties.organization')->all());

        $members = $this->actingInOrganization($owner, $org)->getJson('/api/v1/audit-logs?action=member.')->assertOk();
        $this->assertSame(['member.invited'], collect($members->json('data'))->pluck('action')->unique()->values()->all());

        $this->actingInOrganization($owner, $org)->getJson('/api/v1/audit-logs?q=Dueña')->assertOk()
            ->assertJsonPath('data.0.actor.name', 'Ana Dueña');
        $this->actingInOrganization($owner, $org)->getJson('/api/v1/audit-logs?q=nadie')->assertOk()->assertJsonCount(0, 'data');

        $this->actingInOrganization($viewer, $org)->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
