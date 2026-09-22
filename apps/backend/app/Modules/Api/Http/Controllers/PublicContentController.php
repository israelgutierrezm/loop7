<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\ContentType;
use App\Modules\Content\Models\ContentItem;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contenido de una marca vía API pública. Lectura con content:read y creación de
 * borradores con content:write. Acotado por Organization (anti-IDOR).
 */
class PublicContentController extends Controller
{
    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        $items = ContentItem::query()
            ->where('brand_id', $brandModel->id)
            ->latest()
            ->paginate((int) $request->integer('per_page', 30))
            ->through(fn (ContentItem $c) => $this->present($c));

        return ApiResponse::paginated($items);
    }

    public function store(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'type' => ['nullable', Rule::in(ContentType::values())],
        ]);

        $content = ContentItem::query()->create([
            'brand_id' => $brandModel->id,
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'type' => $data['type'] ?? ContentType::POST->value,
            'status' => 'draft',
        ]);

        return ApiResponse::success($this->present($content), 'Contenido creado.', status: 201);
    }

    private function resolveBrand(string $publicId): Brand
    {
        return Brand::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ContentItem $c): array
    {
        return [
            'id' => $c->public_id,
            'title' => $c->title,
            'body' => $c->body,
            'type' => $c->type->value,
            'status' => $c->status->value,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
