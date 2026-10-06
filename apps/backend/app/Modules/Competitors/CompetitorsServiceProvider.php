<?php

declare(strict_types=1);

namespace App\Modules\Competitors;

use App\Modules\Competitors\Console\SyncCompetitorsCommand;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Análisis de competidores (docs/05): cuentas públicas de la competencia en las
 * redes cuya API oficial lo permite, con una foto diaria y la comparación con
 * la marca.
 */
class CompetitorsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([SyncCompetitorsCommand::class]);
    }

    protected function bootModule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('competitors:sync-due')->dailyAt('06:15')->withoutOverlapping();
        });
    }
}
