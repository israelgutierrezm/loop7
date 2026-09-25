<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\MediaLibrary\Models\MediaFolder;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carpetas de la biblioteca de una Brand (un nivel). Organizarlas exige
 * content.update; al borrar una carpeta sus archivos pasan a "Sin carpeta".
 */
class MediaFoldersController extends Controller
{
    use ResolvesBrand;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        $folders = MediaFolder::query()
            ->where('brand_id', $brandModel->id)
            ->withCount('assets')
            ->orderBy('name')
            ->get()
            ->map(fn (MediaFolder $f) => $this->present($f))
            ->all();

        return ApiResponse::success($folders, meta: [
            'total' => MediaAsset::query()->where('brand_id', $brandModel->id)->count(),
            'unfiled' => MediaAsset::query()->where('brand_id', $brandModel->id)->whereNull('folder_id')->count(),
        ]);
    }

    public function store(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('content.update'), 403);

        $name = $this->validatedName($request, $brandModel);
        $folder = MediaFolder::query()->create([
            'organization_id' => $brandModel->organization_id,
            'brand_id' => $brandModel->id,
            'name' => $name,
        ]);
        $this->audit->log(AuditAction::MEDIA_FOLDER_SAVED, $folder, ['name' => $name]);

        return ApiResponse::success($this->present($folder->loadCount('assets')), 'Carpeta creada.', status: 201);
    }

    public function update(Request $request, string $folder): JsonResponse
    {
        $model = $this->resolveFolder($folder);
        abort_unless($request->user()->can('content.update'), 403);

        /** @var Brand $brand */
        $brand = $model->brand;
        $name = $this->validatedName($request, $brand, $model->id);
        $model->update(['name' => $name]);
        $this->audit->log(AuditAction::MEDIA_FOLDER_SAVED, $model, ['name' => $name]);

        return ApiResponse::success($this->present($model->loadCount('assets')), 'Carpeta renombrada.');
    }

    public function destroy(Request $request, string $folder): JsonResponse
    {
        $model = $this->resolveFolder($folder);
        abort_unless($request->user()->can('content.update'), 403);

        DB::transaction(function () use ($model): void {
            MediaAsset::query()->withTrashed()->where('folder_id', $model->id)->update(['folder_id' => null]);
            $this->audit->log(AuditAction::MEDIA_FOLDER_DELETED, $model, ['name' => $model->name]);
            $model->delete();
        });

        return ApiResponse::message('Carpeta eliminada: sus archivos quedan sin carpeta.');
    }

    private function validatedName(Request $request, Brand $brand, ?int $ignoreId = null): string
    {
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:60']])['name']);

        $duplicate = MediaFolder::query()
            ->where('brand_id', $brand->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Ya existe una carpeta con ese nombre.']);
        }

        return $name;
    }

    private function resolveFolder(string $publicId): MediaFolder
    {
        $folder = MediaFolder::query()->with('brand')->where('public_id', $publicId)->firstOrFail();
        $this->authorizeBrand($folder->brand);

        return $folder;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MediaFolder $folder): array
    {
        return [
            'id' => $folder->public_id,
            'name' => $folder->name,
            'count' => (int) ($folder->assets_count ?? 0),
        ];
    }
}
