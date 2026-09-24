<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Brands\Http\Resources\BrandResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Http\Resources\OrganizationResource;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contexto operativo de la Organization actual: datos, permisos efectivos del
 * usuario y Brands a las que tiene acceso. Alimenta el estado global del SPA.
 */
class ContextController extends Controller
{
    public function show(
        Request $request,
        MembershipService $memberships,
        TenantContext $context,
        EntitlementsService $entitlements,
        SubscriptionService $subscriptions,
    ): JsonResponse {
        $organization = $context->organization();
        $user = $request->user();

        $permissions = $memberships->permissionsFor($user, $organization);
        // Rol(es) del usuario en la Organization actual, para que el contexto sea
        // coherente con /me y el frontend pueda mostrarlos.
        $organization->current_roles = $memberships->rolesFor($user, $organization);

        $hasAllBrands = $organization->users()
            ->where('users.id', $user->id)
            ->wherePivot('all_brands_access', true)
            ->exists();

        // El OrganizationScope ya limita a la Organization actual.
        $brands = $hasAllBrands
            ? Brand::query()->orderBy('name')->get()
            : $user->accessibleBrands()
                ->where('brands.organization_id', $organization->id)
                ->orderBy('name')
                ->get();

        $subscription = $subscriptions->find($organization);

        return ApiResponse::success([
            'organization' => (new OrganizationResource($organization))->toArray($request),
            'permissions' => $permissions,
            'brands' => $brands->map(fn (Brand $b) => (new BrandResource($b))->toArray($request))->all(),
            // Plan vigente y features: la UI adapta flujos y avisos (el backend siempre valida).
            'entitlements' => $entitlements->forOrganization($organization),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
                'grants_access' => $subscription->grantsAccess(),
                'plan_name' => $subscription->plan?->name,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
            ],
        ]);
    }
}
