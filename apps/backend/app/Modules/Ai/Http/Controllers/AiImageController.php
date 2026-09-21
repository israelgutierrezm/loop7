<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Services\AiGenerationService;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Generación de imágenes con IA acotada a una Brand (permiso ai.generate_image).
 */
class AiImageController extends Controller
{
    use ResolvesBrand;

    public function __construct(private readonly AiGenerationService $generation)
    {
    }

    public function generate(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('ai.generate_image'), 403);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'size' => ['nullable', Rule::in(['1024x1024', '1024x1792', '1792x1024'])],
        ]);

        $result = $this->generation->generateImage(
            $brandModel,
            $request->user(),
            $data['prompt'],
            $data['size'] ?? '1024x1024',
        );

        return ApiResponse::success($result, 'Imagen generada.');
    }
}
