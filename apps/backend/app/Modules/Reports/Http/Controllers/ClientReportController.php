<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Reports\Enums\ReportPeriod;
use App\Modules\Reports\Enums\ReportSection;
use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Enums\RunTrigger;
use App\Modules\Reports\Http\Requests\GenerateClientReportRequest;
use App\Modules\Reports\Http\Requests\SaveClientReportRequest;
use App\Modules\Reports\Models\ClientReport;
use App\Modules\Reports\Models\ClientReportRun;
use App\Modules\Reports\Services\ClientReportService;
use App\Modules\Reports\Services\ReportDataBuilder;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Informes para clientes de una marca (docs/05). Ver y descargar exige
 * `analytics.view` y acceso a la marca; crear, cambiar, generar y enviar,
 * `analytics.reports`. Todo se resuelve dentro de la marca (sin IDOR).
 */
class ClientReportController extends Controller
{
    use ResolvesBrand;

    /** Vida del enlace firmado de descarga del PDF. */
    private const DOWNLOAD_MINUTES = 30;

    private const RECENT_RUNS = 5;

    public function __construct(
        private readonly ClientReportService $reports,
        private readonly ReportDataBuilder $builder,
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_VIEW), 403);
        $brandModel = $this->resolveBrand($brand);
        $organization = $this->organization();

        $items = ClientReport::query()
            ->where('brand_id', $brandModel->id)
            ->orderBy('name')
            ->get();
        $runs = ClientReportRun::query()
            ->whereIn('client_report_id', $items->pluck('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('client_report_id');

        return ApiResponse::success([
            'reports' => $items->map(fn (ClientReport $r): array => $this->present($r, $brandModel, $runs->get($r->id, collect())->take(self::RECENT_RUNS)->all()))->all(),
            'usage' => $this->reports->usage($organization),
            'sections' => array_map(fn (ReportSection $s): array => [
                'value' => $s->value,
                'label' => $s->label(),
                'available' => $this->builder->sectionAvailable($organization, $s->value),
            ], ReportSection::cases()),
            'periods' => array_map(fn (ReportPeriod $p): array => ['value' => $p->value, 'label' => $p->label()], ReportPeriod::cases()),
            'timezone' => $brandModel->timezone ?: 'UTC',
        ]);
    }

    public function store(SaveClientReportRequest $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $report = $this->reports->create($this->organization(), $brandModel, $request->report(), $request->user());

        return ApiResponse::success($this->present($report, $brandModel, []), 'Informe creado.', status: 201);
    }

    public function update(SaveClientReportRequest $request, string $brand, string $report): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $model = $this->reports->update($this->resolve($brandModel, $report), $brandModel, $request->report());

        return ApiResponse::success($this->present($model, $brandModel, $this->recentRuns($model)), 'Informe guardado.');
    }

    public function destroy(Request $request, string $brand, string $report): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_REPORTS), 403);
        $this->reports->delete($this->resolve($this->resolveBrand($brand), $report));

        return ApiResponse::message('Informe eliminado.');
    }

    /**
     * Informes generados (historial), del más reciente al más antiguo.
     */
    public function runs(Request $request, string $brand, string $report): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_VIEW), 403);
        $brandModel = $this->resolveBrand($brand);
        $model = $this->resolve($brandModel, $report);

        $page = ClientReportRun::query()
            ->where('client_report_id', $model->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);
        $page->setCollection($page->getCollection()->map(fn (ClientReportRun $run): array => $this->presentRun($run)));

        return ApiResponse::paginated($page);
    }

    /**
     * Genera el PDF del periodo (sin enviarlo).
     */
    public function generate(GenerateClientReportRequest $request, string $brand, string $report): JsonResponse
    {
        return $this->run($request, $brand, $report, RunTrigger::MANUAL, 'Generando el informe…');
    }

    /**
     * Genera el PDF y lo envía ahora a los destinatarios.
     */
    public function send(GenerateClientReportRequest $request, string $brand, string $report): JsonResponse
    {
        return $this->run($request, $brand, $report, RunTrigger::SEND, 'Generando y enviando el informe…');
    }

    private function run(GenerateClientReportRequest $request, string $brand, string $report, RunTrigger $trigger, string $message): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $model = $this->resolve($brandModel, $report);
        $this->reports->ensureIncluded($this->organization());

        [$from, $to] = $request->range($model->period, $brandModel->timezone ?: 'UTC');
        $run = $this->reports->requestRun($model, $trigger, $from, $to, $request->user());

        return ApiResponse::success($this->presentRun($run->refresh()), $message, status: 202);
    }

    private function resolve(Brand $brand, string $publicId): ClientReport
    {
        return ClientReport::query()->where('brand_id', $brand->id)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return list<ClientReportRun>
     */
    private function recentRuns(ClientReport $report): array
    {
        return ClientReportRun::query()
            ->where('client_report_id', $report->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_RUNS)
            ->get()
            ->all();
    }

    /**
     * @param  list<ClientReportRun>  $runs
     * @return array<string, mixed>
     */
    private function present(ClientReport $report, Brand $brand, array $runs): array
    {
        return [
            'id' => $report->public_id,
            'name' => $report->name,
            'period' => $report->period->value,
            'period_label' => $report->period->label(),
            'sections' => $report->sections,
            'intro' => $report->intro,
            'recipients' => $report->recipients,
            'frequency' => $report->frequency?->value,
            'send_day' => $report->send_day,
            'send_hour' => $report->send_hour,
            'timezone' => $brand->timezone ?: 'UTC',
            'next_send_at' => $report->next_send_at?->toIso8601String(),
            'last_sent_at' => $report->last_sent_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
            'runs' => array_map(fn (ClientReportRun $run): array => $this->presentRun($run), $runs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(ClientReportRun $run): array
    {
        $summary = $run->data['summary'] ?? null;

        return [
            'id' => $run->public_id,
            'period_from' => $run->period_from->toDateString(),
            'period_to' => $run->period_to->toDateString(),
            'trigger' => $run->trigger->value,
            'status' => $run->status->value,
            'error' => $run->error,
            'file_size' => $run->file_size,
            'recipients' => $run->recipients ?? [],
            'delivered' => count($run->delivered_to ?? []),
            'sent_at' => $run->sent_at?->toIso8601String(),
            'created_at' => $run->created_at->toIso8601String(),
            'summary' => is_array($summary) ? [
                'followers' => $summary['followers'] ?? null,
                'reach' => $summary['reach']['value'] ?? null,
                'engagement' => $summary['engagement']['value'] ?? null,
                'posts' => $summary['posts']['value'] ?? null,
            ] : null,
            // Enlace firmado y de corta vida: el PDF nunca es público.
            'download_url' => $run->status === RunStatus::READY && $run->file_path !== null
                ? URL::temporarySignedRoute('reports.download', now()->addMinutes(self::DOWNLOAD_MINUTES), ['run' => $run->public_id])
                : null,
        ];
    }

    private function organization(): Organization
    {
        $organization = $this->tenant->organization();
        abort_if($organization === null, 403);

        return $organization;
    }
}
