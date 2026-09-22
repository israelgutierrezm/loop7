<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Route;

class ApiServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // API pública con su propio prefijo y autenticación por API key.
        $public = $this->modulePath() . '/routes/public.php';
        if (file_exists($public)) {
            Route::prefix('api/public/v1')->group($public);
        }
    }
}
