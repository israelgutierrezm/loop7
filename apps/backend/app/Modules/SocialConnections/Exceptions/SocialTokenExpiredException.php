<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Exceptions;

use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El proveedor rechazó el token (caducado, revocado o sin permisos). La
 * conexión pasa a "Expirada" y el usuario debe reconectarla.
 */
class SocialTokenExpiredException extends SocialProviderException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error(
                'La conexión con la red social expiró. Reconecta la cuenta e inténtalo de nuevo.',
                'social_token_expired',
                [],
                409,
            );
        }

        return null;
    }
}
