<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Content\Models\ContentItem;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    use ResolvesBrand;

    /**
     * Contenido programado de una Brand dentro de un rango de fechas (calendario).
     */
    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('content.view'), 403);

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : now()->endOfMonth();

        $items = ContentItem::query()
            ->where('brand_id', $brandModel->id)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from, $to])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (ContentItem $c) => [
                'id' => $c->public_id,
                'title' => $c->title,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            ])
            ->all();

        return ApiResponse::success($items);
    }
}
