<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Contrato de proveedor social. El dominio nunca se acopla a Meta/LinkedIn/etc.:
 * interactúa a través de este contrato (docs/06). Cubre el ciclo de vida de la
 * conexión OAuth, la publicación y la lectura de métricas.
 */
interface SocialProviderInterface
{
    /**
     * Identificador estable (facebook, instagram, linkedin, fake, …).
     */
    public function key(): string;

    /**
     * Capacidades del proveedor (Capability Matrix).
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;

    /**
     * ¿Usa PKCE en el Authorization Code Flow?
     */
    public function usesPkce(): bool;

    /**
     * Scopes mínimos por defecto.
     *
     * @return list<string>
     */
    public function defaultScopes(): array;

    /**
     * URL de autorización (Authorization Code Flow).
     *
     * @param  list<string>  $scopes
     * @param  array<string, string>  $credentials
     */
    public function authorizeUrl(
        string $redirectUri,
        string $state,
        ?string $codeChallenge,
        array $scopes,
        array $credentials,
    ): string;

    /**
     * Intercambia el authorization code por tokens.
     *
     * @param  array<string, string>  $credentials
     */
    public function exchangeCode(
        string $code,
        string $redirectUri,
        ?string $codeVerifier,
        array $credentials,
    ): OAuthTokens;

    /**
     * Renueva los tokens con el refresh token.
     *
     * @param  array<string, string>  $credentials
     */
    public function refreshTokens(string $refreshToken, array $credentials): OAuthTokens;

    /**
     * Etiqueta legible de la cuenta conectada (nombre de usuario/página).
     *
     * @param  array<string, string>  $credentials
     */
    public function accountLabel(OAuthTokens $tokens, array $credentials): string;

    /**
     * Destinos publicables disponibles para la conexión.
     *
     * @param  array<string, string>  $credentials
     * @return list<RemoteDestination>
     */
    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array;

    /**
     * Publica en un destino concreto. Debe ser idempotente respecto a
     * $payload->idempotencyKey cuando el proveedor lo permita.
     *
     * @param  array<string, string>  $credentials
     */
    public function publish(
        OAuthTokens $tokens,
        string $destinationExternalId,
        PublishPayload $payload,
        array $credentials,
    ): PublishResult;

    /**
     * Métricas actuales de la cuenta/página (analítica de cuenta).
     *
     * @param  array<string, string>  $credentials
     */
    public function fetchAccountMetrics(
        OAuthTokens $tokens,
        string $destinationExternalId,
        array $credentials,
    ): AccountMetrics;

    /**
     * Métricas actuales de una publicación por su id remoto (analítica de post).
     *
     * @param  array<string, string>  $credentials
     */
    public function fetchPostMetrics(
        OAuthTokens $tokens,
        string $remoteId,
        array $credentials,
    ): PostMetrics;

    /**
     * Conversaciones (comentarios/DMs/menciones) del destino (inbox, docs/05).
     *
     * @param  array<string, string>  $credentials
     * @return list<InboxThread>
     */
    public function fetchConversations(
        OAuthTokens $tokens,
        string $destinationExternalId,
        array $credentials,
    ): array;

    /**
     * Responde a una conversación. Debe ser idempotente cuando el proveedor lo permita.
     *
     * @param  array<string, string>  $credentials
     */
    public function replyToConversation(
        OAuthTokens $tokens,
        string $conversationExternalId,
        string $body,
        array $credentials,
    ): InboxReplyResult;
}
