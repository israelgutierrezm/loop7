<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Api\Models\ApiKey;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la API key de la petición tenga un scope concreto (docs/11).
 * Uso: 'scope:content:read'.
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->attributes->get('api_key');

        if (! $key instanceof ApiKey || ! $key->hasScope($scope)) {
            return ApiResponse::error(
                'La API key no tiene el permiso necesario: ' . $scope,
                'insufficient_scope',
                ['required_scope' => $scope],
                403,
            );
        }

        return $next($request);
    }
}
