<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;

/**
 * Red que permite borrar desde la app una publicación ya hecha (docs/06:
 * «deleteRemotePost() cuando exista»). Instagram y TikTok no lo permiten.
 */
interface DeletesRemotePosts
{
    /**
     * Borra la publicación. Si la red confirma que ya no existe, no falla: el
     * borrado es idempotente.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws SocialTokenExpiredException el token caducó o se revocó
     * @throws SocialProviderException la red no lo permitió (mensaje para la persona)
     */
    public function deleteRemotePost(OAuthTokens $tokens, string $remoteId, array $credentials): void;
}
