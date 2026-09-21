<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Services\AuditLogger;
use App\Support\Providers\ModuleServiceProvider;

class AuditServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditLogger::class);
    }
}
