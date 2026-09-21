<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Exceptions\ProviderNotConfiguredException;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador de Meta (Facebook Pages). Implementa el flujo OAuth real de Graph
 * API; funcionará en cuanto se configuren credenciales válidas y la app pase la
 * revisión de Meta. Antes de producción: revisar permisos, quotas y políticas
 * oficiales vigentes (docs/06).
 */
class FacebookProvider implements SocialProviderInterface
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    public function key(): string
    {
        return 'facebook';
    }

    public function capabilities(): array
    {
        return [
            Capability::TEXT => true,
            Capability::IMAGE => true,
            Capability::MULTI_IMAGE => true,
            Capability::VIDEO => true,
            Capability::SHORT_VIDEO => true,
            Capability::STORY => true,
            Capability::CAROUSEL => true,
            Capability::LINK => true,
            Capability::SCHEDULE_NATIVE => true,
            Capability::COMMENTS_READ => true,
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function defaultScopes(): array
    {
        return [
            'public_profile',
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
        ];
    }

    public function authorizeUrl(
        string $redirectUri,
        string $state,
        ?string $codeChallenge,
        array $scopes,
        array $credentials,
    ): string {
        $this->assertConfigured($credentials);

        return 'https://www.facebook.com/v21.0/dialog/oauth?' . http_build_query([
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

        $response = Http::get(self::GRAPH . '/oauth/access_token', [
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new ProviderNotConfiguredException('Meta rechazó el intercambio del código: ' . $response->body());
        }

        $data = $response->json();

        return new OAuthTokens(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: null, // Meta usa long-lived tokens, no refresh_token clásico
            expiresAt: isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
            scopes: $this->defaultScopes(),
        );
    }

    public function refreshTokens(string $refreshToken, array $credentials): OAuthTokens
    {
        $this->assertConfigured($credentials);

        // Meta: intercambio por token de larga duración.
        $response = Http::get(self::GRAPH . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'fb_exchange_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new ProviderNotConfiguredException('Meta no pudo renovar el token: ' . $response->body());
        }

        $data = $response->json();

        return new OAuthTokens(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: null,
            expiresAt: isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
            scopes: $this->defaultScopes(),
        );
    }

    public function accountLabel(OAuthTokens $tokens, array $credentials): string
    {
        $response = Http::get(self::GRAPH . '/me', [
            'fields' => 'name',
            'access_token' => $tokens->accessToken,
        ]);

        return $response->successful()
            ? (string) ($response->json('name') ?? 'Cuenta de Meta')
            : 'Cuenta de Meta';
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $response = Http::get(self::GRAPH . '/me/accounts', [
            'fields' => 'id,name,category',
            'access_token' => $tokens->accessToken,
        ]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('data') ?? [])
            ->map(fn (array $page) => new RemoteDestination(
                externalId: (string) $page['id'],
                name: (string) ($page['name'] ?? 'Página'),
                type: 'page',
                capabilities: $this->capabilities(),
                metadata: ['category' => $page['category'] ?? null],
            ))
            ->all();
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function assertConfigured(array $credentials): void
    {
        if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new ProviderNotConfiguredException(
                'Configura las credenciales de Meta (client_id y client_secret) en el panel SUPERADMIN.',
            );
        }
    }
}
