<?php

declare(strict_types=1);

namespace App\Modules\Automations\Exceptions;

use RuntimeException;

/**
 * El feed no se pudo leer. El mensaje es apto para mostrarlo al usuario.
 */
class FeedException extends RuntimeException
{
}
