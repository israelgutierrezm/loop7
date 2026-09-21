<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use App\Support\Http\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * No hay ningún proveedor de IA habilitado y configurado para la modalidad
 * solicitada. Se traduce a HTTP 503 (servicio no disponible).
 */
class AiProviderNotConfiguredException extends Exception
{
    public function __construct(string $message = 'No hay un proveedor de IA configurado para esta operación.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error($this->getMessage(), 'ai_provider_unavailable', [], 503);
        }

        return null;
    }
}
