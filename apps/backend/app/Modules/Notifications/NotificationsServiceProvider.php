<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Console\PruneNotificationsCommand;
use App\Modules\Notifications\Listeners\SendDomainNotifications;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * Avisos in-app y por correo (docs/05 "Notifications"): escucha eventos de
 * dominio de los demás módulos y avisa a las personas adecuadas.
 */
class NotificationsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([PruneNotificationsCommand::class]);
    }

    protected function bootModule(): void
    {
        Event::subscribe(SendDomainNotifications::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('notifications:prune')->dailyAt('03:30')->withoutOverlapping();
        });
    }
}
