<?php

declare(strict_types=1);

namespace App\Modules\Automations;

use App\Modules\Automations\Console\PollFeedsCommand;
use App\Modules\Automations\Console\ResumeWaitingRunsCommand;
use App\Modules\Automations\Listeners\DisableAutomationsOfDeletedBrand;
use App\Modules\Automations\Listeners\RunAutomationsForContentPublished;
use App\Modules\Automations\Listeners\RunAutomationsForInboxMessage;
use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Inbox\Events\InboxMessageReceived;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class AutomationsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([PollFeedsCommand::class, ResumeWaitingRunsCommand::class]);
    }

    protected function bootModule(): void
    {
        // Escucha eventos internos de otros módulos y dispara las reglas que
        // coincidan, sin acoplar esos módulos a Automations (docs/05).
        Event::listen(ContentPublished::class, RunAutomationsForContentPublished::class);
        Event::listen(InboxMessageReceived::class, RunAutomationsForInboxMessage::class);
        Event::listen(BrandDeleted::class, DisableAutomationsOfDeletedBrand::class);

        // Webhooks entrantes: por URL (token), no por IP, para que una
        // integración ruidosa no afecte a otras.
        RateLimiter::for('automation-inbound', function (Request $request): array {
            $key = sha1((string) $request->route('token'));

            return [Limit::perMinute(60)->by('min:' . $key), Limit::perDay(5000)->by('day:' . $key)];
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('automations:poll-feeds')->everyFiveMinutes()->withoutOverlapping();
            // Pasos «Esperar» vencidos.
            $schedule->command('automations:resume-waiting')->everyMinute()->withoutOverlapping();
        });
    }
}
