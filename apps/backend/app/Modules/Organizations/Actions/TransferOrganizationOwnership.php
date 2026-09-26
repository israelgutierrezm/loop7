<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Transfiere la propiedad de una Organization a otro miembro activo: pasa a
 * OWNER (con acceso a todas las marcas) y el propietario anterior queda como
 * ADMIN. Atómica y auditada.
 */
class TransferOrganizationOwnership
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(Organization $organization, User $from, User $to): void
    {
        DB::transaction(function () use ($organization, $from, $to): void {
            $this->registrar->setPermissionsTeamId($organization->id);

            $to->unsetRelation('roles');
            $to->syncRoles([OrganizationRole::OWNER->value]);
            $from->unsetRelation('roles');
            $from->syncRoles([OrganizationRole::ADMIN->value]);

            // El propietario ve todas las marcas: sin restricciones residuales.
            $organization->users()->updateExistingPivot($to->id, ['all_brands_access' => true]);
            DB::table('brand_user_access')
                ->where('user_id', $to->id)
                ->where('organization_id', $organization->id)
                ->delete();

            $organization->forceFill(['owner_user_id' => $to->id])->save();

            $this->audit->log(AuditAction::ORGANIZATION_OWNERSHIP_TRANSFERRED, $organization, [
                'from' => $from->public_id,
                'to' => $to->public_id,
                'to_email' => $to->email,
            ], actor: $from);
        });
    }
}
