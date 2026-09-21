<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use App\Support\Http\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Se ha alcanzado el límite del plan para un recurso. Se traduce a HTTP 402.
 */
class PlanLimitExceededException extends Exception
{
    public function __construct(
        string $message = 'Has alcanzado el límite de tu plan.',
        public readonly ?string $entitlement = null,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error(
                $this->getMessage(),
                'plan_limit_reached',
                $this->entitlement !== null ? ['entitlement' => $this->entitlement] : [],
                402,
            );
        }

        return null;
    }
}
