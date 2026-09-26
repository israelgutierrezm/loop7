<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

use App\Modules\AccessControl\Services\RoleCatalog;
use App\Support\Providers\ModuleServiceProvider;

class AccessControlServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Memoiza el catálogo de roles por request (se consulta varias veces por petición).
        $this->app->scoped(RoleCatalog::class);
    }
}
