<?php

declare(strict_types=1);

namespace App\Modules\Ai;

use App\Modules\Ai\Services\AiProviderManager;
use App\Support\Providers\ModuleServiceProvider;

class AiServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProviderManager::class);
    }
}
