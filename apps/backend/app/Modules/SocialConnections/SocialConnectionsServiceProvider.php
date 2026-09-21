<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections;

use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Providers\ModuleServiceProvider;

class SocialConnectionsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocialProviderManager::class);
    }
}
