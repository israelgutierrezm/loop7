<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Requests\Concerns;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Sso\Services\SsoConfiguration;
use App\Support\Tenancy\TenantContext;

/**
 * Cambiar el SSO exige `organization.update` y un plan con feature.sso; el plan
 * se comprueba antes de validar los datos (402 aunque falten campos).
 */
trait AuthorizesSsoSettings
{
    public function authorize(): bool
    {
        if (! (bool) $this->user()?->can(Permission::ORGANIZATION_UPDATE)) {
            return false;
        }

        $organization = app(TenantContext::class)->organization();
        if ($organization === null || ! app(SsoConfiguration::class)->available($organization)) {
            throw new PlanLimitExceededException('Tu plan no incluye inicio de sesión único (SSO).', Entitlement::FEATURE_SSO);
        }

        return true;
    }
}
