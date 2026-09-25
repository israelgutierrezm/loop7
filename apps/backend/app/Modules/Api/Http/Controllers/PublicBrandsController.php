<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Models\Brand;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Listado de marcas de la organización de la API key (scope brands:read).
 * El OrganizationScope garantiza el aislamiento (anti-IDOR).
 */
class PublicBrandsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Brand::query()
            ->orderBy('name')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 30))))
            ->through(fn (Brand $b) => [
                'id' => $b->public_id,
                'name' => $b->name,
                'slug' => $b->slug,
                'status' => $b->status->value,
            ]);

        return ApiResponse::paginated($items);
    }
}
