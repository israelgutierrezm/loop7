<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
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
    public function show(Request $request, MembershipService $memberships, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $user = $request->user();

        $permissions = $memberships->permissionsFor($user, $organization);

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

        return ApiResponse::success([
            'organization' => (new OrganizationResource($organization))->toArray($request),
            'permissions' => $permissions,
            'brands' => $brands->map(fn (Brand $b) => (new BrandResource($b))->toArray($request))->all(),
        ]);
    }
}
