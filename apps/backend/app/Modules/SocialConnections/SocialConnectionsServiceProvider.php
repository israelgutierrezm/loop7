<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\SocialConnections\Console\RefreshSocialTokensCommand;
use App\Modules\SocialConnections\Listeners\DisconnectAccountsOfDeletedBrand;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class SocialConnectionsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocialProviderManager::class);
        $this->commands([RefreshSocialTokensCommand::class]);
    }

    protected function bootModule(): void
    {
        Event::listen(BrandDeleted::class, DisconnectAccountsOfDeletedBrand::class);

        // Cada hora: renueva tokens por caducar y marca los que ya no se pueden renovar.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('social:refresh-tokens')->hourly()->withoutOverlapping();
        });
    }
}
