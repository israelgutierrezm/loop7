<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Enums\Capability;
use Illuminate\Support\Str;

/**
 * Proveedor social simulado. Implementa el flujo completo sin depender de una
 * API externa: sirve para desarrollo y pruebas end-to-end del framework OAuth.
 */
class FakeSocialProvider implements SocialProviderInterface
{
    public function key(): string
    {
        return 'fake';
    }

    public function capabilities(): array
    {
        return [
            Capability::TEXT => true,
            Capability::IMAGE => true,
            Capability::MULTI_IMAGE => true,
            Capability::VIDEO => true,
            Capability::LINK => true,
            Capability::CAROUSEL => true,
            Capability::SCHEDULE_NATIVE => false,
            Capability::COMMENTS_READ => true,
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function usesPkce(): bool
    {
        return true;
    }

    public function defaultScopes(): array
    {
        return ['read', 'write'];
    }

    public function authorizeUrl(
        string $redirectUri,
        string $state,
        ?string $codeChallenge,
        array $scopes,
        array $credentials,
    ): string {
        // Atajo de desarrollo: en lugar de una pantalla OAuth externa inexistente,
        // apunta directamente al callback con un código simulado, para que el
        // flujo completo sea demostrable desde el frontend.
        return $redirectUri . '?' . http_build_query([
            'code' => 'fake-' . Str::random(10),
            'state' => $state,
        ]);
    }

    public function exchangeCode(
        string $code,
        string $redirectUri,
        ?string $codeVerifier,
        array $credentials,
    ): OAuthTokens {
        return new OAuthTokens(
            accessToken: 'fake-access-' . $code,
            refreshToken: 'fake-refresh-' . $code,
            expiresAt: now()->addHour(),
            scopes: $this->defaultScopes(),
        );
    }

    public function refreshTokens(string $refreshToken, array $credentials): OAuthTokens
    {
        return new OAuthTokens(
            accessToken: 'fake-access-refreshed',
            refreshToken: $refreshToken,
            expiresAt: now()->addHour(),
            scopes: $this->defaultScopes(),
        );
    }

    public function accountLabel(OAuthTokens $tokens, array $credentials): string
    {
        return 'Cuenta de demostración';
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        return [
            new RemoteDestination('fake-page-1', 'Página Demo', 'page', $this->capabilities()),
            new RemoteDestination('fake-profile-1', 'Perfil Demo', 'profile', $this->capabilities()),
        ];
    }
}
