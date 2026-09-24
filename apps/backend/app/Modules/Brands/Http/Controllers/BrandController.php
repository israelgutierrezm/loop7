<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Http\Resources\BrandResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandAccess;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {
    }

    public function index(Request $request, BrandAccess $access): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);

        $user = $request->user();

        // El OrganizationScope garantiza que sólo se vean Brands de la Org actual.
        $query = $access->hasAllBrands($user)
            ? Brand::query()
            : Brand::query()->whereIn('id', $user->accessibleBrands()->select('brands.id'));

        $brands = $query->orderBy('name')
            ->paginate((int) $request->integer('per_page', 20))
            ->through(fn (Brand $b) => new BrandResource($b));

        return ApiResponse::paginated($brands);
    }

    public function store(Request $request, EntitlementsService $entitlements): JsonResponse
    {
        $this->authorize('create', Brand::class);

        // Límite de plan: nº de marcas (docs/15 "plan limita brands").
        $organization = $this->context->organization();
        $currentBrands = Brand::query()->count(); // acotado a la Org por OrganizationScope
        if (! $entitlements->withinLimit($organization, Entitlement::BRANDS_MAX, $currentBrands)) {
            throw new PlanLimitExceededException(
                'Has alcanzado el número de marcas incluidas en tu plan.',
                Entitlement::BRANDS_MAX,
            );
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'primary_color' => ['nullable', 'string', 'max:9'],
            'secondary_color' => ['nullable', 'string', 'max:9'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        $brand = new Brand($data);
        $brand->slug = $this->uniqueSlug($data['name']);
        // organization_id lo autocompleta el trait BelongsToOrganization.
        $brand->save();

        // Otorga acceso al creador para consistencia con Brand Access.
        $brand->grantAccessTo($request->user()->id);

        $this->audit->log(AuditAction::BRAND_CREATED, $brand, ['name' => $brand->name]);

        return ApiResponse::success(new BrandResource($brand), 'Marca creada.', status: 201);
    }

    public function show(string $brand): JsonResponse
    {
        $model = $this->resolve($brand);
        $this->authorize('view', $model);

        return ApiResponse::success(new BrandResource($model));
    }

    public function update(Request $request, string $brand): JsonResponse
    {
        $model = $this->resolve($brand);
        $this->authorize('update', $model);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'primary_color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'secondary_color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],
        ]);

        $model->fill($data)->save();
        $this->audit->log(AuditAction::BRAND_UPDATED, $model, ['changes' => array_keys($data)]);

        return ApiResponse::success(new BrandResource($model), 'Marca actualizada.');
    }

    public function destroy(string $brand): JsonResponse
    {
        $model = $this->resolve($brand);
        $this->authorize('delete', $model);

        $this->audit->log(AuditAction::BRAND_DELETED, $model, ['name' => $model->name]);
        $model->delete();

        return ApiResponse::message('Marca eliminada.');
    }

    /**
     * Resuelve una Brand por public_id dentro de la Organization actual.
     * El OrganizationScope evita acceder a Brands de otra Org (anti-IDOR).
     */
    private function resolve(string $publicId): Brand
    {
        return Brand::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'marca';
        $slug = $base;
        $organizationId = $this->context->organizationId();

        while (Brand::withTrashed()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }
}
