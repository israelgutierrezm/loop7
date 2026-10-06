<?php

declare(strict_types=1);

namespace App\Modules\Sso\Listeners;

use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Modules\Sso\Models\OrganizationDomain;
use App\Modules\Sso\Models\SsoConnection;
use App\Support\Tenancy\OrganizationScope;

/**
 * Una organización eliminada deja de iniciar sesión por SSO y libera sus
 * dominios verificados (otra organización podrá verificarlos).
 */
class ReleaseSsoOfDeletedOrganization
{
    public function handle(OrganizationDeleted $event): void
    {
        OrganizationDomain::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $event->organization->id)
            ->delete();
        SsoConnection::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $event->organization->id)
            ->delete();
    }
}
