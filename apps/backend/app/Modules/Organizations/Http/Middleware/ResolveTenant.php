<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Middleware;

use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la Organization (y opcionalmente la Brand) de la request y la fija
 * en el TenantContext.
 *
 * Seguridad: la Organization se busca SIEMPRE dentro de las membresías del
 * usuario autenticado, por lo que un public_id ajeno nunca resuelve (anti-IDOR).
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('No autenticado.', 'unauthenticated', status: 401);
        }

        $organizationPublicId = $request->header('X-Organization')
            ?? $request->route('organization');

        $query = $user->organizations()->wherePivot('status', 'active');

        $organization = is_string($organizationPublicId) && $organizationPublicId !== ''
            ? $query->where('organizations.public_id', $organizationPublicId)->first()
            : $query->first();

        if ($organization === null) {
            return ApiResponse::error(
                'No perteneces a esta organización o no se indicó una organización válida.',
                'organization_not_resolved',
                status: 403,
            );
        }

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $context->setOrganization($organization);

        $this->resolveBrand($request, $user, $context);

        return $next($request);
    }

    private function resolveBrand(Request $request, \App\Models\User $user, TenantContext $context): void
    {
        $brandPublicId = $request->header('X-Brand');

        if (! is_string($brandPublicId) || $brandPublicId === '') {
            return;
        }

        $organization = $context->organization();
        $brand = $organization?->brands()->where('public_id', $brandPublicId)->first();

        if ($brand === null) {
            return;
        }

        // El usuario debe tener acceso: a todas las Brands o a esta en concreto.
        // Aquí $organization no es null (si lo fuera, $brand sería null y ya habríamos salido).
        $hasAllBrands = (bool) $organization->users()
            ->where('users.id', $user->id)
            ->wherePivot('all_brands_access', true)
            ->exists();

        $hasExplicitAccess = $brand->usersWithAccess()
            ->where('users.id', $user->id)
            ->exists();

        if ($hasAllBrands || $hasExplicitAccess) {
            $context->setBrand($brand);
        }
    }
}
