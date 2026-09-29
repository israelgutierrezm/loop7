<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Http\Requests\BestTimesRequest;
use App\Modules\Analytics\Services\AnalyticsQueryService;
use App\Modules\Analytics\Services\BestTimesService;
use App\Modules\Analytics\Services\MetricsSyncService;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Support\Csv\CsvWriter;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboards de analítica acotados por Brand. La lectura usa el permiso
 * analytics.view; la exportación analytics.export + feature.analytics_advanced
 * y los mejores horarios analytics.view + feature.analytics_advanced.
 */
class AnalyticsController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly AnalyticsQueryService $query,
        private readonly MetricsSyncService $sync,
        private readonly BestTimesService $bestTimes,
        private readonly EntitlementsService $entitlements,
        private readonly TenantContext $tenant,
    ) {
    }

    public function overview(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('analytics.view'), 403);

        [$from, $to] = $this->range($request);

        $data = $this->query->overview($brandModel, $from, $to);
        $data['top_posts'] = $this->query->topPosts($brandModel, $from, $to, 10);

        $organization = $this->tenant->organization();
        $data['can_export'] = $request->user()->can('analytics.export')
            && $organization !== null
            && $this->entitlements->allows($organization, Entitlement::FEATURE_ANALYTICS_ADVANCED);

        return ApiResponse::success($data);
    }

    public function sync(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('analytics.view'), 403);

        $counts = $this->sync->syncBrand($brandModel);

        return ApiResponse::success($counts, 'Métricas actualizadas.');
    }

    public function export(Request $request, string $brand): StreamedResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('analytics.export'), 403);
        $this->ensureAdvanced('La exportación de analítica requiere un plan con analítica avanzada.');

        [$from, $to] = $this->range($request);
        $rows = $this->query->topPosts($brandModel, $from, $to, 500);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            CsvWriter::start($out);
            CsvWriter::row($out, ['Publicación', 'Red', 'Destino', 'Impresiones', 'Alcance', 'Me gusta', 'Comentarios', 'Compartidos', 'Interacciones']);
            foreach ($rows as $r) {
                // Título y destino los escriben personas: CsvWriter neutraliza fórmulas.
                CsvWriter::row($out, [
                    $r['title'], $r['provider'], $r['destination'],
                    $r['impressions'], $r['reach'], $r['likes'], $r['comments'], $r['shares'], $r['engagement'],
                ]);
            }
            fclose($out);
        }, "analitica-{$brandModel->slug}-{$from->toDateString()}-{$to->toDateString()}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Mejores horarios para publicar: mapa de calor por día y hora (zona de la
     * marca), franjas recomendadas y sus próximas fechas dentro del rango.
     */
    public function bestTimes(BestTimesRequest $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('analytics.view'), 403);
        $this->ensureAdvanced('Los mejores horarios para publicar requieren un plan con analítica avanzada.');

        [$from, $to] = $request->range();

        return ApiResponse::success($this->bestTimes->forBrand($brandModel, $request->providers(), $from, $to));
    }

    private function ensureAdvanced(string $message): void
    {
        $organization = $this->tenant->organization();
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_ANALYTICS_ADVANCED)) {
            throw new PlanLimitExceededException($message, Entitlement::FEATURE_ANALYTICS_ADVANCED);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->startOfDay() : Carbon::today();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : $to->copy()->subDays(29);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        // Límite de seguridad: máximo 365 días por consulta.
        if ($from->diffInDays($to) > 365) {
            $from = $to->copy()->subDays(365);
        }

        return [$from, $to];
    }
}
