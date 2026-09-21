<?php

declare(strict_types=1);

namespace App\Modules\Inbox;

use App\Modules\Inbox\Console\SyncInboxCommand;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class InboxServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([SyncInboxCommand::class]);
    }

    protected function bootModule(): void
    {
        // Sincronización periódica del inbox (docs/05). En producción con Redis+Horizon.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('inbox:sync-due')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
