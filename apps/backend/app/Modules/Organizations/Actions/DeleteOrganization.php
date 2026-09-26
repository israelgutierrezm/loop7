<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Enums\InvitationStatus;
use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Borrado (lógico) de una Organization con su limpieza atómica: invitaciones
 * pendientes revocadas y, vía OrganizationDeleted, marcas (publicaciones,
 * cuentas sociales, automatizaciones), API keys y suscripción.
 */
class DeleteOrganization
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function handle(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            OrganizationInvitation::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->where('status', InvitationStatus::PENDING->value)
                ->update(['status' => InvitationStatus::REVOKED->value]);

            $this->audit->log(AuditAction::ORGANIZATION_DELETED, $organization, [
                'name' => $organization->name,
            ], organizationId: $organization->id);

            $organization->delete();
            OrganizationDeleted::dispatch($organization);
        });
    }
}
