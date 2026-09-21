<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Services\ContentService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentVariantsController extends Controller
{
    public function __construct(private readonly ContentService $content)
    {
    }

    public function store(Request $request, string $content): JsonResponse
    {
        $model = ContentItem::query()->where('public_id', $content)->firstOrFail();
        abort_unless($request->user()->can('content.update'), 403);

        $data = $request->validate([
            'provider' => ['required', 'string', 'max:32'],
            'body' => ['nullable', 'string', 'max:20000'],
            'format' => ['nullable', 'string', 'max:24'],
            'options' => ['nullable', 'array'],
            'media' => ['nullable', 'array'],
        ]);

        $variant = $this->content->addVariant($model, $data);

        return ApiResponse::success($this->present($variant->load('media')), 'Variante añadida.', status: 201);
    }

    public function update(Request $request, string $variant): JsonResponse
    {
        $model = $this->resolveVariant($variant);
        abort_unless($request->user()->can('content.update'), 403);

        $data = $request->validate([
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'format' => ['sometimes', 'string', 'max:24'],
            'options' => ['sometimes', 'nullable', 'array'],
        ]);

        $model->update($data);

        return ApiResponse::success($this->present($model->load('media')), 'Variante actualizada.');
    }

    public function syncMedia(Request $request, string $variant): JsonResponse
    {
        $model = $this->resolveVariant($variant);
        abort_unless($request->user()->can('content.update'), 403);

        $data = $request->validate([
            'media' => ['present', 'array'],
            'media.*' => ['string'],
        ]);

        $this->content->syncMedia($model, $data['media']);

        return ApiResponse::success($this->present($model->load('media')), 'Multimedia actualizada.');
    }

    public function destroy(Request $request, string $variant): JsonResponse
    {
        $model = $this->resolveVariant($variant);
        abort_unless($request->user()->can('content.update'), 403);

        $model->delete();

        return ApiResponse::message('Variante eliminada.');
    }

    private function resolveVariant(string $publicId): PostVariant
    {
        return PostVariant::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PostVariant $variant): array
    {
        return [
            'id' => $variant->public_id,
            'provider' => $variant->provider,
            'body' => $variant->body,
            'format' => $variant->format,
            'options' => $variant->options,
            'media' => $variant->media->map(fn ($m) => [
                'id' => $m->public_id,
                'original_name' => $m->original_name,
            ])->all(),
        ];
    }
}
