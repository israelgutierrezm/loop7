<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

use Illuminate\Support\Carbon;

/**
 * Tokens OAuth normalizados devueltos por un proveedor.
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
    ) {
    }
}
