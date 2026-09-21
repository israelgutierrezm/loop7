<?php

declare(strict_types=1);

namespace App\Support\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base para los ServiceProviders de módulo del monolito modular.
 *
 * Convención por módulo (carpeta del provider):
 *   Database/Migrations/   → migraciones del módulo
 *   routes/api.php         → rutas API v1 del módulo
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>  modelo => policy
     */
    protected array $policies = [];

    public function boot(): void
    {
        $base = $this->modulePath();

        if (is_dir($base . '/Database/Migrations')) {
            $this->loadMigrationsFrom($base . '/Database/Migrations');
        }

        $apiRoutes = $base . '/routes/api.php';
        if (file_exists($apiRoutes)) {
            Route::middleware('api')
                ->prefix('api/v1')
                ->group($apiRoutes);
        }

        foreach ($this->policies as $model => $policy) {
            \Illuminate\Support\Facades\Gate::policy($model, $policy);
        }

        $this->bootModule();
    }

    /**
     * Hook opcional para lógica adicional de arranque del módulo.
     */
    protected function bootModule(): void
    {
    }

    protected function modulePath(): string
    {
        return dirname((new ReflectionClass($this))->getFileName());
    }
}
