<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Exceptions;

use RuntimeException;

/**
 * El proveedor externo aún no está completamente configurado/implementado
 * (falta la revisión de app y/o credenciales reales). El MVP usa los contratos
 * aunque algunos adapters estén incompletos (docs/20).
 */
class ProviderNotConfiguredException extends RuntimeException
{
}
