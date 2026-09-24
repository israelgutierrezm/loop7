<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\MediaLibrary\Http\Requests\UploadMediaRequest;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\MediaLibrary\Services\MediaService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        $assets = MediaAsset::query()
            ->where('brand_id', $brandModel->id)
            ->latest()
            ->paginate((int) $request->integer('per_page', 30))
            ->through(fn (MediaAsset $a) => $this->present($a));

        return ApiResponse::paginated($assets);
    }

    public function store(UploadMediaRequest $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('content.create'), 403);

        $file = $request->file('file');

        // Límite de almacenamiento del plan.
        $organization = $brandModel->organization;
        if ($organization !== null) {
            $this->media->ensureStorageAvailable($organization, (int) $file->getSize());
        }

        $asset = $this->media->upload($brandModel, $file, null, $request->user());
        $this->audit->log(AuditAction::BRAND_UPDATED, $asset, [
            'brand' => $brandModel->public_id,
            'media' => $asset->public_id,
        ]);

        return ApiResponse::success($this->present($asset), 'Archivo subido.', status: 201);
    }

    public function destroy(Request $request, string $asset): JsonResponse
    {
        /** @var MediaAsset $model */
        $model = MediaAsset::query()->where('public_id', $asset)->firstOrFail();
        $this->resolveBrand($model->brand->public_id); // valida acceso a la Brand
        abort_unless($request->user()->can('content.delete'), 403);

        $this->media->delete($model);

        return ApiResponse::message('Archivo eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MediaAsset $asset): array
    {
        return [
            'id' => $asset->public_id,
            'original_name' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'extension' => $asset->extension,
            'size_bytes' => $asset->size_bytes,
            'width' => $asset->width,
            'height' => $asset->height,
            'is_image' => $asset->isImage(),
            'is_video' => $asset->isVideo(),
            'url' => $this->media->temporaryUrl($asset),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}
