<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\AnalyticsQueryService;
use App\Modules\Brands\Models\Brand;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Analítica de una marca vía API pública (scope analytics:read). Reutiliza el
 * servicio de consultas del módulo Analytics. Acotado por Organization.
 */
class PublicAnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsQueryService $query)
    {
    }

    public function overview(Request $request, string $brand): JsonResponse
    {
        $brandModel = Brand::query()->where('public_id', $brand)->firstOrFail();

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->startOfDay() : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        // Mismo límite que la app: máximo 365 días por consulta.
        if ($from->diffInDays($to) > 365) {
            $from = $to->copy()->subDays(365);
        }

        return ApiResponse::success($this->query->overview($brandModel, $from, $to));
    }
}
