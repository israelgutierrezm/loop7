<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Red que permite revocar desde la app el acceso concedido (las políticas de
 * YouTube lo exigen al desconectar). Al revocar, la red invalida los tokens:
 * sólo se llama cuando ninguna otra conexión usa la misma cuenta.
 */
interface RevokesAccess
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function revokeAccess(OAuthTokens $tokens, array $credentials): void;
}
