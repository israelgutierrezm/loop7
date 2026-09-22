<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Añade cabeceras de seguridad a todas las respuestas (docs/19). HSTS sólo sobre
 * HTTPS para no fijar la política en entornos locales por HTTP.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /** @var array<string, string> $headers */
        $headers = config('security.headers', []);
        foreach ($headers as $name => $value) {
            if ($value !== '' && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure() && (bool) config('security.hsts.enabled', true)) {
            $response->headers->set('Strict-Transport-Security', (string) config('security.hsts.value'));
        }

        return $response;
    }
}
