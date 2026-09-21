<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Enums\AiOperation;
use App\Modules\Ai\Services\AiGenerationService;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Generación de texto con IA acotada a una Brand. La autorización usa el permiso
 * granular ai.generate_text; los créditos y el aislamiento los aplica el servicio.
 */
class AiTextController extends Controller
{
    use ResolvesBrand;

    public function __construct(private readonly AiGenerationService $generation)
    {
    }

    public function generate(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('ai.generate_text'), 403);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:5000'],
            'operation' => ['nullable', Rule::in(array_map(fn (AiOperation $o) => $o->value, AiOperation::cases()))],
            'network' => ['nullable', 'string', 'max:32'],
        ]);

        $operation = AiOperation::tryFrom($data['operation'] ?? '') ?? AiOperation::GENERATE_POST;

        $result = $this->generation->generateText(
            $brandModel,
            $request->user(),
            $data['prompt'],
            $operation,
            $data['network'] ?? null,
        );

        return ApiResponse::success($result, 'Contenido generado.');
    }
}
