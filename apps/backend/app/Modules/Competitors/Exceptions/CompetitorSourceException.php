<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Exceptions;

use RuntimeException;

/**
 * Fallo al leer una cuenta de la competencia (red, token o permisos de la app).
 * El mensaje es apto para mostrar: nunca incluye tokens.
 */
class CompetitorSourceException extends RuntimeException
{
}
