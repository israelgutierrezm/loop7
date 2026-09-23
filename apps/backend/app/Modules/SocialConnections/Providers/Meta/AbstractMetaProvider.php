<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers\Meta;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\RemoteAccount;
use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Exceptions\ProviderNotConfiguredException;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use Illuminate\Support\Carbon;

/**
 * OAuth común de Meta (Facebook Login) para Facebook e Instagram: misma app,
 * mismo diálogo y mismo canje de tokens. Tras el código se canjea un token de
 * larga duración (~60 días); los page tokens obtenidos con él no caducan y se
 * guardan cifrados por destino, así la conexión no expira a las pocas horas.
 */
abstract class AbstractMetaProvider implements SocialProviderInterface
{
    public function usesPkce(): bool
    {
        return false;
    }

    public function authorizeUrl(
        string $redirectUri,
        string $state,
        ?string $codeChallenge,
        array $scopes,
        array $credentials,
    ): string {
        $this->assertConfigured($credentials);

        return MetaGraph::fromCredentials($credentials)->dialogUrl() . '?' . http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', $scopes),
        ]);
    }

    public function exchangeCode(
        string $code,
        string $redirectUri,
        ?string $codeVerifier,
        array $credentials,
    ): OAuthTokens {
        $this->assertConfigured($credentials);
        $graph = MetaGraph::fromCredentials($credentials);

        $short = $graph->get('oauth/access_token', [
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ], 'completar la autorización');

        try {
            return $this->longLived($graph, (string) ($short['access_token'] ?? ''), $credentials);
        } catch (SocialProviderException) {
            // Sin canje de larga duración: se conserva el token corto con su caducidad.
            return new OAuthTokens(
                accessToken: (string) ($short['access_token'] ?? ''),
                expiresAt: isset($short['expires_in']) ? Carbon::now()->addSeconds((int) $short['expires_in']) : null,
                scopes: $this->defaultScopes(),
            );
        }
    }

    public function refreshTokens(string $refreshToken, array $credentials): OAuthTokens
    {
        $this->assertConfigured($credentials);

        return $this->longLived(MetaGraph::fromCredentials($credentials), $refreshToken, $credentials);
    }

    public function verifyCredentials(array $credentials): void
    {
        $this->assertConfigured($credentials);

        // Un app access token sólo se emite si client_id/secret son correctos.
        MetaGraph::fromCredentials($credentials)->get('oauth/access_token', [
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'grant_type' => 'client_credentials',
        ], 'validar las credenciales de la app');
    }

    public function fetchAccount(OAuthTokens $tokens, array $credentials): RemoteAccount
    {
        $me = MetaGraph::fromCredentials($credentials)->get('me', [
            'fields' => 'id,name',
            'access_token' => $tokens->accessToken,
        ], 'leer la cuenta');

        return new RemoteAccount((string) ($me['id'] ?? ''), (string) ($me['name'] ?? 'Cuenta de Meta'));
    }

    /**
     * Token del destino si existe (page token, no caduca); si no, el de la cuenta.
     */
    protected function destinationToken(OAuthTokens $tokens): string
    {
        $token = $tokens->destinationToken ?? '';

        return $token !== '' ? $token : $this->accountToken($tokens);
    }

    /**
     * Token de la cuenta; una conexión sin token no puede operar y debe reconectarse.
     */
    protected function accountToken(OAuthTokens $tokens): string
    {
        if ($tokens->accessToken === '') {
            throw new SocialTokenExpiredException('La conexión no tiene un token de acceso válido.');
        }

        return $tokens->accessToken;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    protected function assertConfigured(array $credentials): void
    {
        if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new ProviderNotConfiguredException(
                'Configura el App ID y el App Secret de Meta en SUPERADMIN → Integraciones sociales.',
            );
        }
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function longLived(MetaGraph $graph, string $token, array $credentials): OAuthTokens
    {
        $data = $graph->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'fb_exchange_token' => $token,
        ], 'obtener un token de larga duración');

        // expiresAt null: lo que publica son los page tokens, que no caducan. Si
        // Meta los revoca (cambio de contraseña, permisos), la API responde 190
        // y la conexión se marca como expirada para reconectarla.
        return new OAuthTokens(
            accessToken: (string) ($data['access_token'] ?? $token),
            scopes: $this->defaultScopes(),
        );
    }
}
