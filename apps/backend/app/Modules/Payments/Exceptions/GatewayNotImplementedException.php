<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * La operación de checkout de esta pasarela externa aún no está implementada
 * (el MVP usa los contratos aunque algunos adapters estén incompletos, docs/20).
 */
class GatewayNotImplementedException extends RuntimeException
{
}
