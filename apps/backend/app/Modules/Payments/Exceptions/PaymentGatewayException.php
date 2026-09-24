<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * La pasarela de pago rechazó la operación o no está bien configurada. El
 * mensaje es legible y nunca incluye credenciales.
 */
class PaymentGatewayException extends RuntimeException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error($this->getMessage(), 'payment_gateway_error', [], 502);
        }

        return null;
    }
}
