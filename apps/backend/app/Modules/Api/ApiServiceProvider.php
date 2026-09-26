<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Modules\Api\Listeners\RevokeApiKeysOfDeletedOrganization;
use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

class ApiServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Event::listen(OrganizationDeleted::class, RevokeApiKeysOfDeletedOrganization::class);

        // API pública con su propio prefijo y autenticación por API key.
        $public = $this->modulePath() . '/routes/public.php';
        if (file_exists($public)) {
            Route::prefix('api/public/v1')->group($public);
        }
    }
}
