<?php

declare(strict_types=1);

namespace App\Modules\Reports\Jobs;

use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Models\ClientReportRun;
use App\Modules\Reports\Services\ReportGenerator;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Genera (y envía, si toca) un informe para clientes (cola `analytics`). Los
 * reintentos sólo repiten lo que faltó: el envío a quien aún no lo recibió.
 */
class GenerateClientReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public int $timeout = 180;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('analytics');
    }

    public function handle(ReportGenerator $generator): void
    {
        $run = ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)->find($this->runId);

        if ($run !== null && $run->status !== RunStatus::FAILED) {
            $generator->process($run);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)->find($this->runId);
        if ($run === null) {
            return;
        }

        app(ReportGenerator::class)->fail(
            $run,
            $run->status === RunStatus::READY ? 'No se pudo enviar por correo a todos los destinatarios.' : 'No se pudo generar el informe.',
        );
    }
}
