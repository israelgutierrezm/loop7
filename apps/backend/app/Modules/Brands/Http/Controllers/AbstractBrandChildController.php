<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\Brand;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD genérico para entidades hijas de una Brand (audiencias, productos,
 * servicios, knowledge). Resuelve y acota por Brand (anti-IDOR) y exige el
 * permiso brands.update para escribir.
 */
abstract class AbstractBrandChildController extends Controller
{
    use ResolvesBrand;

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function present(Model $model): array;

    public function store(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('brands.update'), 403);

        $class = $this->modelClass();
        $model = $class::query()->create(array_merge($request->validate($this->rules()), [
            'organization_id' => $brandModel->organization_id,
            'brand_id' => $brandModel->id,
        ]));

        return ApiResponse::success($this->present($model), 'Creado.', status: 201);
    }

    public function update(Request $request, string $brand, string $child): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('brands.update'), 403);

        $model = $this->resolveChild($brandModel, $child);
        $model->update($request->validate($this->rules()));

        return ApiResponse::success($this->present($model), 'Actualizado.');
    }

    public function destroy(Request $request, string $brand, string $child): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('brands.update'), 403);

        $this->resolveChild($brandModel, $child)->delete();

        return ApiResponse::message('Eliminado.');
    }

    protected function resolveChild(Brand $brand, string $publicId): Model
    {
        $class = $this->modelClass();

        return $class::query()
            ->where('brand_id', $brand->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
