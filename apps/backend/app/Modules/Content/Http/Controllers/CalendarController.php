<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
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

        // Rango acotado (como mucho ~6 semanas visibles en la vista mensual).
        if ($from->diffInDays($to, true) > 62) {
            $to = $from->copy()->addDays(62);
        }

        $items = ContentItem::query()
            ->where('brand_id', $brandModel->id)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from, $to])
            ->with(['variants:id,content_item_id,provider', 'campaign:id,public_id,name'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (ContentItem $c) => [
                'id' => $c->public_id,
                'title' => $c->title,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'scheduled_at' => $c->scheduled_at?->toIso8601String(),
                'providers' => $c->variants->map(fn (PostVariant $v) => $v->provider)->unique()->values()->all(),
                'campaign' => $c->campaign?->name,
            ])
            ->all();

        return ApiResponse::success($items);
    }
}
