<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contexto de tenant único por request.
        $this->app->scoped(TenantContext::class, fn () => new TenantContext());
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        // El enlace de restablecimiento de contraseña apunta al SPA (Vue).
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return "{$frontend}/restablecer-contrasena?token={$token}&email={$email}";
        });
    }

    private function configureRateLimiting(): void
    {
        // Límite general de la API por usuario/IP.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Límite estricto para endpoints de autenticación (anti fuerza bruta).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip()));
    }
}
