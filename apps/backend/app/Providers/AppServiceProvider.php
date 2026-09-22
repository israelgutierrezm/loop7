<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        // En producción todas las URLs generadas (redirect_uri OAuth, URLs
        // firmadas de media, enlaces de correo) deben ser https.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

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

        // Límite para los jobs de publicación social (protege las APIs externas).
        RateLimiter::for('social-publish', fn () => Limit::perMinute(60));

        // Límite de la API pública por API key (o IP si aún no está resuelta).
        RateLimiter::for('public-api', function (Request $request) {
            $keyId = $request->attributes->get('api_key_id');

            return $keyId !== null
                ? Limit::perMinute(120)->by('apikey:' . $keyId)
                : Limit::perMinute(30)->by($request->ip());
        });
    }
}
