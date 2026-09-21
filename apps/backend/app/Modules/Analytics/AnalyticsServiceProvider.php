<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Modules\Analytics\Console\SeedDemoMetricsCommand;
use App\Modules\Analytics\Console\SyncMetricsCommand;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class AnalyticsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([SyncMetricsCommand::class, SeedDemoMetricsCommand::class]);
    }

    protected function bootModule(): void
    {
        // Sincronización diaria de métricas (docs/05). En producción con Redis+Horizon.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('analytics:sync-due')->dailyAt('05:00')->withoutOverlapping();
        });
    }
}
