<?php

declare(strict_types=1);

namespace App\Modules\Reports\Console;

use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Reports\Enums\RunTrigger;
use App\Modules\Reports\Models\ClientReport;
use App\Modules\Reports\Services\ClientReportService;
use App\Support\Tenancy\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Envíos programados vencidos (cada 15 min): cada informe se reclama de forma
 * atómica moviendo su próximo envío, así nunca sale dos veces aunque coincidan
 * dos procesos. Si la marca, la organización o el plan ya no lo permiten, esa
 * ocurrencia se omite.
 */
class SendDueReportsCommand extends Command
{
    protected $signature = 'reports:send-due';

    protected $description = 'Genera y envía los informes para clientes cuyo envío programado venció';

    public function handle(ClientReportService $reports): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        ClientReport::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereNotNull('frequency')
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', $now)
            ->chunkById(100, function (Collection $batch) use ($reports, $now, &$sent): void {
                foreach ($batch as $report) {
                    /** @var ClientReport $report */
                    $brand = Brand::query()->withoutGlobalScope(OrganizationScope::class)
                        ->where('organization_id', $report->organization_id)
                        ->find($report->brand_id);
                    $next = $brand !== null ? $reports->nextSend($report, $brand) : null;

                    $claimed = ClientReport::query()->withoutGlobalScope(OrganizationScope::class)
                        ->whereKey($report->id)
                        ->where('next_send_at', $report->next_send_at)
                        ->update(['next_send_at' => $next]);
                    if ($claimed === 0 || $brand === null || $report->recipients === []) {
                        continue;
                    }

                    $organization = Organization::query()->operational()->find($report->organization_id);
                    if ($organization === null || ! $reports->included($organization)) {
                        continue;
                    }

                    [$from, $to] = $report->period->range($brand->timezone ?: 'UTC', $now);
                    try {
                        $reports->requestRun($report, RunTrigger::SCHEDULED, $from, $to, null);
                        $sent++;
                    } catch (ValidationException) {
                        // Ya se estaba generando: esta ocurrencia no se duplica.
                    }
                }
            });

        $this->info("Informes programados en marcha: {$sent}.");

        return self::SUCCESS;
    }
}
