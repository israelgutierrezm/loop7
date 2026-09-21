<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Exceptions;

use RuntimeException;

/**
 * El parámetro `state` del callback OAuth es inválido, ya se usó o expiró.
 * Protege contra CSRF y replay en el flujo de autorización.
 */
class InvalidOAuthStateException extends RuntimeException
{
}
