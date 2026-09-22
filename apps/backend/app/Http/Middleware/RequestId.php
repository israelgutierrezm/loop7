<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asigna un correlation id por request (X-Request-Id) y lo añade al contexto de
 * logs y a la respuesta (docs/13 observabilidad). Acepta uno entrante si tiene un
 * formato seguro; si no, genera un UUID.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
