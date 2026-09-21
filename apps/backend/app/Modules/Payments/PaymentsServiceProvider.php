<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Services\GatewayManager;
use App\Support\Providers\ModuleServiceProvider;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GatewayManager::class);
    }
}
