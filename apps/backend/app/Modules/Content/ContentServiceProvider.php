<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Content\Console\PublishDueContent;
use App\Modules\Content\Listeners\CancelPublicationsOfDeletedBrand;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class ContentServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([PublishDueContent::class]);
    }

    protected function bootModule(): void
    {
        Event::listen(BrandDeleted::class, CancelPublicationsOfDeletedBrand::class);

        // Cada minuto: despacha los targets programados vencidos (motor de publicación).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('content:publish-due')->everyMinute()->withoutOverlapping();
        });
    }
}
