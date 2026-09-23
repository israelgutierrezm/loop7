<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Exceptions;

use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * La API del proveedor social rechazó la operación (permisos, formato, límites).
 * El mensaje es legible y nunca incluye tokens ni secretos.
 */
class SocialProviderException extends RuntimeException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error($this->getMessage(), 'social_provider_error', [], 502);
        }

        return null;
    }
}
