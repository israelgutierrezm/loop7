<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

use Illuminate\Support\Carbon;

/**
 * Tokens OAuth normalizados devueltos por un proveedor.
 *
 * `destinationToken` es el token propio del destino cuando el proveedor lo
 * emite (p. ej. el page access token de Meta, que no caduca); si existe, los
 * adaptadores lo prefieren al token de la cuenta.
 */
final class OAuthTokens
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly array $scopes = [],
        public readonly ?string $destinationToken = null,
    ) {
    }
}
