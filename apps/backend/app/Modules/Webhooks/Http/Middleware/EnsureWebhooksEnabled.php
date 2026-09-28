<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Middleware;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los webhooks se gestionan desde «API y accesos»: exigen el permiso
 * api.manage (403) y un plan con API (402), antes de validar nada.
 */
class EnsureWebhooksEnabled
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->can(Permission::API_MANAGE), 403);

        $organization = $this->tenant->organization();
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_API)) {
            throw new PlanLimitExceededException('Tu plan no incluye la API ni los webhooks.', Entitlement::FEATURE_API);
        }

        return $next($request);
    }
}
