<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Modules\Content\Console\PublishDueContent;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ContentServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([PublishDueContent::class]);
    }

    protected function bootModule(): void
    {
        // Cada minuto: despacha los targets programados vencidos (motor de publicación).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('content:publish-due')->everyMinute()->withoutOverlapping();
        });
    }
}
