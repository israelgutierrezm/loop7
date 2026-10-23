<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Reports\Console\PruneReportsCommand;
use App\Modules\Reports\Console\SendDueReportsCommand;
use App\Modules\Reports\Contracts\ReportRenderer;
use App\Modules\Reports\Listeners\StopReportsOfDeletedBrand;
use App\Modules\Reports\Rendering\DompdfReportRenderer;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * Informes para clientes (docs/05): PDF por marca y periodo con la marca blanca
 * de la organización, historial y envío programado por correo.
 */
class ReportsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportRenderer::class, DompdfReportRenderer::class);
        $this->commands([SendDueReportsCommand::class, PruneReportsCommand::class]);
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath() . '/resources/views', 'reports');

        Event::listen(BrandDeleted::class, StopReportsOfDeletedBrand::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('reports:send-due')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('reports:prune')->dailyAt('03:50')->withoutOverlapping();
        });
    }
}
