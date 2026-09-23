<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\SocialConnections\Contracts\AccountMetrics;
use App\Modules\SocialConnections\Contracts\InboxMessageData;
use App\Modules\SocialConnections\Contracts\InboxReplyResult;
use App\Modules\SocialConnections\Contracts\InboxThread;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PostMetrics;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Contracts\PublishResult;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Exceptions\ProviderNotConfiguredException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
            'pages_manage_engagement',
            'read_insights',
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

    public function publish(
        OAuthTokens $tokens,
        string $destinationExternalId,
        PublishPayload $payload,
        array $credentials,
    ): PublishResult {
        $pageToken = $this->pageToken($destinationExternalId, $tokens->accessToken);

        if ($payload->mediaUrls !== []) {
            // Publica la primera imagen con el texto como pie (photos edge).
            $response = Http::asForm()->post(self::GRAPH . '/' . $destinationExternalId . '/photos', [
                'url' => $payload->mediaUrls[0],
                'caption' => $payload->body,
                'access_token' => $pageToken,
            ]);
        } else {
            $response = Http::asForm()->post(self::GRAPH . '/' . $destinationExternalId . '/feed', [
                'message' => $payload->body,
                'access_token' => $pageToken,
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException('Meta rechazó la publicación: ' . $response->body());
        }

        $remoteId = (string) ($response->json('post_id') ?? $response->json('id') ?? '');

        return new PublishResult($remoteId, 'https://www.facebook.com/' . $remoteId);
    }

    public function fetchAccountMetrics(
        OAuthTokens $tokens,
        string $destinationExternalId,
        array $credentials,
    ): AccountMetrics {
        $pageToken = $this->pageToken($destinationExternalId, $tokens->accessToken);

        $page = Http::get(self::GRAPH . '/' . $destinationExternalId, [
            'fields' => 'fan_count,followers_count',
            'access_token' => $pageToken,
        ])->json();

        $insights = Http::get(self::GRAPH . '/' . $destinationExternalId . '/insights', [
            'metric' => 'page_impressions,page_impressions_unique,page_post_engagements',
            'period' => 'day',
            'access_token' => $pageToken,
        ])->json('data', []);

        return new AccountMetrics(
            followers: (int) ($page['followers_count'] ?? $page['fan_count'] ?? 0),
            reach: $this->latestInsightValue($insights, 'page_impressions_unique'),
            impressions: $this->latestInsightValue($insights, 'page_impressions'),
            engagement: $this->latestInsightValue($insights, 'page_post_engagements'),
            postsCount: 0,
        );
    }

    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        $pageToken = $this->pageToken($this->pageIdFromObjectId($remoteId), $tokens->accessToken);

        $post = Http::get(self::GRAPH . '/' . $remoteId, [
            'fields' => 'likes.summary(true),comments.summary(true),shares',
            'access_token' => $pageToken,
        ])->json();

        $insights = Http::get(self::GRAPH . '/' . $remoteId . '/insights', [
            'metric' => 'post_impressions,post_impressions_unique,post_clicks',
            'access_token' => $pageToken,
        ])->json('data', []);

        return new PostMetrics(
            impressions: $this->latestInsightValue($insights, 'post_impressions'),
            reach: $this->latestInsightValue($insights, 'post_impressions_unique'),
            likes: (int) ($post['likes']['summary']['total_count'] ?? 0),
            comments: (int) ($post['comments']['summary']['total_count'] ?? 0),
            shares: (int) ($post['shares']['count'] ?? 0),
            clicks: $this->latestInsightValue($insights, 'post_clicks'),
        );
    }

    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $pageToken = $this->pageToken($destinationExternalId, $tokens->accessToken);

        $feed = Http::get(self::GRAPH . '/' . $destinationExternalId . '/feed', [
            'fields' => 'comments.limit(25){id,message,created_time,from}',
            'limit' => 25,
            'access_token' => $pageToken,
        ])->json('data', []);

        $threads = [];
        foreach ($feed as $post) {
            foreach ($post['comments']['data'] ?? [] as $comment) {
                $sentAt = isset($comment['created_time']) ? Carbon::parse($comment['created_time']) : now();
                $author = (string) ($comment['from']['name'] ?? 'Usuario');
                $authorId = (string) ($comment['from']['id'] ?? '');
                $threads[] = new InboxThread(
                    externalId: (string) $comment['id'],
                    type: 'comment',
                    participantName: $author,
                    participantExternalId: $authorId,
                    lastMessageAt: $sentAt,
                    messages: [new InboxMessageData(
                        externalId: (string) $comment['id'],
                        authorName: $author,
                        authorExternalId: $authorId,
                        body: (string) ($comment['message'] ?? ''),
                        direction: 'inbound',
                        sentAt: $sentAt,
                    )],
                );
            }
        }

        return $threads;
    }

    public function replyToConversation(
        OAuthTokens $tokens,
        string $conversationExternalId,
        string $body,
        array $credentials,
    ): InboxReplyResult {
        $pageToken = $this->pageToken($this->pageIdFromObjectId($conversationExternalId), $tokens->accessToken);

        $response = Http::asForm()->post(self::GRAPH . '/' . $conversationExternalId . '/comments', [
            'message' => $body,
            'access_token' => $pageToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Meta rechazó la respuesta: ' . $response->body());
        }

        return new InboxReplyResult((string) ($response->json('id') ?? ''));
    }

    /**
     * Obtiene el page access token a partir del id de la página y el token de
     * usuario (no se almacena: se deriva bajo demanda del token cifrado).
     */
    private function pageToken(string $pageId, string $userToken): string
    {
        $response = Http::get(self::GRAPH . '/' . $pageId, [
            'fields' => 'access_token',
            'access_token' => $userToken,
        ]);

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('No se pudo obtener el token de la página ' . $pageId . '.');
        }

        return $token;
    }

    /**
     * Los ids de posts/comentarios de Página tienen la forma {pageId}_{...}.
     */
    private function pageIdFromObjectId(string $objectId): string
    {
        return str_contains($objectId, '_') ? explode('_', $objectId)[0] : $objectId;
    }

    /**
     * Último valor de una métrica de Insights (data → item.name → values[].value).
     *
     * @param  array<int, array<string, mixed>>  $insights
     */
    private function latestInsightValue(array $insights, string $name): int
    {
        foreach ($insights as $item) {
            if (($item['name'] ?? null) !== $name) {
                continue;
            }
            $values = $item['values'] ?? [];
            $last = is_array($values) && $values !== [] ? end($values) : null;

            return (int) ($last['value'] ?? 0);
        }

        return 0;
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
