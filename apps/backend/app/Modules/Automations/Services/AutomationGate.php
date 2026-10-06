<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Models\User;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Support\Tenancy\TenantContext;

/**
 * Antes de validar nada: permiso (403) y plan con automatizaciones (402).
 */
final class AutomationGate
{
    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly TenantContext $tenant,
    ) {
    }

    public function ensure(User $user, string $permission): void
    {
        abort_unless($user->can($permission), 403);

        $organization = $this->tenant->organization();
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_AUTOMATIONS)) {
            throw new PlanLimitExceededException('Tu plan no incluye automatizaciones.', Entitlement::FEATURE_AUTOMATIONS);
        }
    }
}
