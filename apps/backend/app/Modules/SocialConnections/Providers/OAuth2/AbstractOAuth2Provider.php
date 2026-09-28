<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers\OAuth2;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Exceptions\ProviderNotConfiguredException;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Support\Security\SecretRedactor;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Base de los adaptadores con OAuth 2.0 estándar (Authorization Code + PKCE):
 * LinkedIn, X, YouTube, TikTok y Threads. Resuelve la URL de autorización, el
 * canje y la renovación de tokens y el manejo de errores HTTP: un token
 * rechazado se traduce en SocialTokenExpiredException (la conexión pasa a
 * «Expirada») y un fallo de red nunca propaga el mensaje de cURL, que puede
 * llevar la URL con credenciales.
 */
abstract class AbstractOAuth2Provider implements SocialProviderInterface
{
    protected const TIMEOUT_SECONDS = 30;

    /** Nombre legible para los mensajes («LinkedIn»). */
    abstract protected function displayName(): string;

    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    public function usesPkce(): bool
    {
        return true;
    }

    /** Separador de scopes en la URL de autorización. */
    protected function scopeSeparator(): string
    {
        return ' ';
    }

    /** Nombre del parámetro con el identificador de la app (TikTok: client_key). */
    protected function clientIdParam(): string
    {
        return 'client_id';
    }

    /** ¿El cliente se autentica con HTTP Basic en el endpoint de tokens? */
    protected function usesBasicAuth(): bool
    {
        return false;
    }

    /**
     * Parámetros adicionales de la URL de autorización.
     *
     * @return array<string, string>
     */
    protected function extraAuthorizeParams(): array
    {
        return [];
    }

    public function authorizeUrl(
        string $redirectUri,
        string $state,
        ?string $codeChallenge,
        array $scopes,
        array $credentials,
    ): string {
        $this->assertConfigured($credentials);

        $params = [
            'response_type' => 'code',
            $this->clientIdParam() => $credentials['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode($this->scopeSeparator(), $scopes),
            ...$this->extraAuthorizeParams(),
        ];
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return $this->authorizeEndpoint() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(
        string $code,
        string $redirectUri,
        ?string $codeVerifier,
        array $credentials,
    ): OAuthTokens {
        $this->assertConfigured($credentials);

        $data = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            ...($codeVerifier !== null ? ['code_verifier' => $codeVerifier] : []),
        ], $credentials, 'completar la autorización');

        return $this->tokensFrom($data);
    }

    public function refreshTokens(string $refreshToken, array $credentials): OAuthTokens
    {
        $this->assertConfigured($credentials);

        $data = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $credentials, 'renovar el acceso');

        return $this->tokensFrom($data, $refreshToken);
    }

    /**
     * Sin una cuenta conectada no hay llamada «inocua» para validar la app: se
     * canjea un código inventado y el proveedor distingue «cliente inválido»
     * (credenciales mal) de «código inválido» (credenciales bien).
     */
    public function verifyCredentials(array $credentials): void
    {
        $this->assertConfigured($credentials);

        $response = $this->send('validar las credenciales de la app', fn () => $this->tokenHttp($credentials)->post(
            $this->tokenEndpoint(),
            $this->withClient([
                'grant_type' => 'authorization_code',
                'code' => 'loop7-verificacion-' . Str::random(8),
                'redirect_uri' => url('/api/v1/social/callback/' . $this->key()),
                'code_verifier' => Str::random(64),
            ], $credentials),
        ));

        $error = strtolower((string) ($this->oauthError($response) ?? ''));
        if ($response->status() === 401 || in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_client_id', 'invalid_client_secret'], true)) {
            throw new SocialProviderException($this->displayName() . ' rechazó las credenciales de la app: revisa el Client ID y el Client Secret.');
        }
    }

    /**
     * @param  array<string, string>  $credentials
     */
    protected function assertConfigured(array $credentials): void
    {
        if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new ProviderNotConfiguredException(
                'Configura el Client ID y el Client Secret de ' . $this->displayName() . ' en SUPERADMIN → Integraciones sociales.',
            );
        }
    }

    /**
     * @param  array<string, string>  $form
     * @param  array<string, string>  $credentials
     * @return array<string, mixed>
     */
    protected function tokenRequest(array $form, array $credentials, string $action): array
    {
        $response = $this->send($action, fn () => $this->tokenHttp($credentials)->post(
            $this->tokenEndpoint(),
            $this->withClient($form, $credentials),
        ));

        $data = (array) $response->json();
        $error = $this->oauthError($response);
        if ($response->failed() || $error !== null || empty($data['access_token'])) {
            $detail = $this->errorMessage($response);

            throw new SocialProviderException($this->displayName() . ' no permitió ' . $action . ': ' . SecretRedactor::redact($detail));
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    protected function tokenHttp(array $credentials): PendingRequest
    {
        $http = Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS);

        return $this->usesBasicAuth()
            ? $http->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
            : $http;
    }

    /**
     * @param  array<string, string>  $form
     * @param  array<string, string>  $credentials
     * @return array<string, string>
     */
    protected function withClient(array $form, array $credentials): array
    {
        if ($this->usesBasicAuth()) {
            return [...$form, 'client_id' => $credentials['client_id']];
        }

        return [...$form, $this->clientIdParam() => $credentials['client_id'], 'client_secret' => $credentials['client_secret']];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function tokensFrom(array $data, ?string $previousRefreshToken = null): OAuthTokens
    {
        $refresh = isset($data['refresh_token']) && is_string($data['refresh_token']) && $data['refresh_token'] !== ''
            ? $data['refresh_token']
            : $previousRefreshToken;

        return new OAuthTokens(
            accessToken: (string) $data['access_token'],
            refreshToken: $refresh,
            expiresAt: isset($data['expires_in']) && is_numeric($data['expires_in'])
                ? Carbon::now()->addSeconds((int) $data['expires_in'])
                : null,
            scopes: $this->scopesFrom($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function scopesFrom(array $data): array
    {
        $scope = $data['scope'] ?? null;
        if (! is_string($scope) || trim($scope) === '') {
            return $this->defaultScopes();
        }

        return array_values(array_filter(preg_split('/[\s,]+/', trim($scope)) ?: []));
    }

    /**
     * Código de error OAuth de una respuesta (`error` de texto), si lo hay.
     */
    protected function oauthError(Response $response): ?string
    {
        $error = $response->json('error');

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * Petición autenticada con el token de la cuenta (Bearer).
     */
    protected function api(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(self::TIMEOUT_SECONDS);
    }

    protected function accessToken(OAuthTokens $tokens): string
    {
        if ($tokens->accessToken === '') {
            throw new SocialTokenExpiredException('La conexión no tiene un token de acceso válido.');
        }

        return $tokens->accessToken;
    }

    /**
     * Ejecuta la petición sin propagar el detalle de cURL (puede llevar la URL
     * con credenciales).
     *
     * @param  callable(): Response  $request
     */
    protected function send(string $action, callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException|TransferException $e) {
            Log::warning($this->displayName() . ': fallo de conexión.', ['error' => SecretRedactor::redact($e->getMessage())]);

            throw new SocialProviderException(
                'No se pudo conectar con ' . $this->displayName() . ' para ' . $action . '. Inténtalo de nuevo en unos minutos.',
            );
        }
    }

    /**
     * Cuerpo JSON de una respuesta correcta; si no, la excepción adecuada.
     *
     * @return array<string, mixed>
     */
    protected function json(Response $response, string $action): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $message = SecretRedactor::redact($this->errorMessage($response));
        if ($this->isTokenError($response)) {
            throw new SocialTokenExpiredException($this->displayName() . ' rechazó el token de acceso: ' . $message);
        }

        throw new SocialProviderException($this->displayName() . ' no permitió ' . $action . ': ' . $message);
    }

    protected function isTokenError(Response $response): bool
    {
        return $response->status() === 401;
    }

    protected function errorMessage(Response $response): string
    {
        foreach (['error.message', 'error_message', 'message', 'error_description', 'detail', 'title', 'error'] as $path) {
            $value = $response->json($path);
            if (is_string($value) && $value !== '') {
                return Str::limit($value, 300);
            }
        }

        return 'HTTP ' . $response->status();
    }

    /**
     * Ajuste de la app (versión de API…) o su valor por defecto si no tiene el
     * formato esperado.
     *
     * @param  array<string, string>  $credentials
     */
    protected function setting(array $credentials, string $key, string $pattern, string $default): string
    {
        $value = (string) ($credentials[$key] ?? '');

        return preg_match($pattern, $value) === 1 ? $value : $default;
    }
}
