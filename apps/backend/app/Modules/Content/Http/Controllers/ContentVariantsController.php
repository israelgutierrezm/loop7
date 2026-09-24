<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Http\Resources\VariantPresenter;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Services\ContentService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContentVariantsController extends Controller
{
    public function __construct(
        private readonly ContentService $content,
        private readonly VariantPresenter $presenter,
        private readonly SocialProviderManager $providers,
    ) {
    }

    public function store(Request $request, string $content): JsonResponse
    {
        $model = ContentItem::query()->where('public_id', $content)->firstOrFail();
        abort_unless($request->user()->can('content.update'), 403);
        $this->assertEditable($model);

        $data = $request->validate([
            // Sólo redes con adaptador implementado.
            'provider' => ['required', 'string', Rule::in(array_keys($this->providers->all()))],
            'body' => ['nullable', 'string', 'max:20000'],
            'format' => ['nullable', 'string', 'max:24'],
            'options' => ['nullable', 'array'],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*' => ['string'],
        ], [
            'provider.in' => 'Esa red social no está disponible.',
        ]);

        $variant = $this->content->addVariant($model, $data);

        return ApiResponse::success($this->presenter->present($variant->load('media')), 'Variante añadida.', status: 201);
    }

    public function update(Request $request, string $variant): JsonResponse
    {
        $model = $this->resolveVariant($variant);
        abort_unless($request->user()->can('content.update'), 403);
        $this->assertEditable($model->contentItem);

        $data = $request->validate([
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'format' => ['sometimes', 'string', 'max:24'],
            'options' => ['sometimes', 'nullable', 'array'],
        ]);

        $model->update($data);

        return ApiResponse::success($this->presenter->present($model->load('media')), 'Variante actualizada.');
    }

    public function syncMedia(Request $request, string $variant): JsonResponse
    {
        $model = $this->resolveVariant($variant);
        abort_unless($request->user()->can('content.update'), 403);
        $this->assertEditable($model->contentItem);

        $data = $request->validate([
            'media' => ['present', 'array', 'max:10'],
            'media.*' => ['string'],
        ]);

        $this->content->syncMedia($model, $data['media']);

        return ApiResponse::success($this->presenter->present($model->load('media')), 'Multimedia actualizada.');
    }

    public function destroy(Request $request, string $variant): JsonResponse
    {
        $model = $this->resolveVariant($variant);
        abort_unless($request->user()->can('content.update'), 403);
        $this->assertEditable($model->contentItem);

        $model->delete();

        return ApiResponse::message('Variante eliminada.');
    }

    private function resolveVariant(string $publicId): PostVariant
    {
        return PostVariant::query()->with('contentItem')->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Una vez aprobado/programado/publicado, el contenido no se modifica (el
     * flujo de aprobación dejaría de tener sentido).
     */
    private function assertEditable(?ContentItem $content): void
    {
        if ($content === null || ! $content->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'El contenido ya no se puede editar en su estado actual.',
            ]);
        }
    }
}
