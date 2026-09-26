<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Exceptions;

use RuntimeException;

/**
 * No se pudo sacar texto de un documento. El mensaje es para el usuario: se
 * muestra tal cual en la lista de documentos.
 */
class DocumentExtractionException extends RuntimeException
{
}
