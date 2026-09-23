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
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\Meta\AbstractMetaProvider;
use App\Modules\SocialConnections\Providers\Meta\MetaGraph;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Adaptador de Instagram (cuentas profesionales vinculadas a una Página, vía
 * Facebook Login). Publica en dos pasos (contenedor → media_publish): imagen,
 * reel/video y carrusel de hasta 10 elementos. Instagram no admite
 * publicaciones sólo de texto y descarga los archivos desde una URL pública.
 */
class InstagramProvider extends AbstractMetaProvider
{
    /** Máximo de elementos de un carrusel de Instagram. */
    private const CAROUSEL_MAX = 10;

    /** Espera máxima al procesamiento de un video: intentos × segundos. */
    private const STATUS_ATTEMPTS = 20;
    private const STATUS_INTERVAL_SECONDS = 3;

    public function key(): string
    {
        return 'instagram';
    }

    public function capabilities(): array
    {
        return [
            Capability::TEXT => false, // requiere imagen o video
            Capability::IMAGE => true,
            Capability::MULTI_IMAGE => true,
            Capability::VIDEO => true,
            Capability::SHORT_VIDEO => true,
            Capability::CAROUSEL => true,
            Capability::COMMENTS_READ => true,
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function defaultScopes(): array
    {
        return [
            'instagram_basic',
            'instagram_content_publish',
            'instagram_manage_comments',
            'instagram_manage_insights',
            'pages_show_list',
            'pages_read_engagement',
        ];
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $pages = MetaGraph::fromCredentials($credentials)->get('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
            'access_token' => $tokens->accessToken,
        ], 'listar tus cuentas de Instagram');

        $destinations = [];
        foreach ((array) ($pages['data'] ?? []) as $page) {
            $account = is_array($page) ? ($page['instagram_business_account'] ?? null) : null;
            if (! is_array($account) || ! isset($account['id'])) {
                continue; // página sin cuenta profesional de Instagram vinculada
            }

            $username = (string) ($account['username'] ?? $account['name'] ?? $account['id']);
            $destinations[] = new RemoteDestination(
                externalId: (string) $account['id'],
                name: '@' . ltrim($username, '@'),
                type: 'instagram_business',
                capabilities: $this->capabilities(),
                metadata: [
                    'page_id' => $page['id'] ?? null,
                    'page_name' => $page['name'] ?? null,
                    'profile_picture_url' => $account['profile_picture_url'] ?? null,
                ],
                accessToken: isset($page['access_token']) ? (string) $page['access_token'] : null,
            );
        }

        return $destinations;
    }

    public function publish(
        OAuthTokens $tokens,
        string $destinationExternalId,
        PublishPayload $payload,
        array $credentials,
    ): PublishResult {
        if ($payload->mediaUrls === []) {
            throw new SocialProviderException('Instagram requiere al menos una imagen o un video.');
        }

        $graph = MetaGraph::fromCredentials($credentials);
        $token = $this->destinationToken($tokens);
        $account = $destinationExternalId;
        $urls = array_slice($payload->mediaUrls, 0, self::CAROUSEL_MAX);

        if (count($urls) === 1) {
            $container = $this->createContainer($graph, $account, $token, $payload->isVideo(0)
                ? ['media_type' => 'REELS', 'video_url' => $urls[0], 'caption' => $payload->body]
                : ['image_url' => $urls[0], 'caption' => $payload->body]);
            if ($payload->isVideo(0)) {
                $this->waitUntilReady($graph, $container, $token);
            }
        } else {
            $children = [];
            foreach ($urls as $i => $url) {
                $child = $this->createContainer($graph, $account, $token, $payload->isVideo($i)
                    ? ['media_type' => 'VIDEO', 'video_url' => $url, 'is_carousel_item' => 'true']
                    : ['image_url' => $url, 'is_carousel_item' => 'true']);
                if ($payload->isVideo($i)) {
                    $this->waitUntilReady($graph, $child, $token);
                }
                $children[] = $child;
            }

            $container = $this->createContainer($graph, $account, $token, [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $children),
                'caption' => $payload->body,
            ]);
            $this->waitUntilReady($graph, $container, $token);
        }

        $media = $graph->post($account . '/media_publish', [
            'creation_id' => $container,
            'access_token' => $token,
        ], 'publicar en Instagram');
        $mediaId = (string) ($media['id'] ?? '');

        return new PublishResult($mediaId, $this->permalink($graph, $mediaId, $token));
    }

    public function fetchAccountMetrics(
        OAuthTokens $tokens,
        string $destinationExternalId,
        array $credentials,
    ): AccountMetrics {
        $graph = MetaGraph::fromCredentials($credentials);
        $token = $this->destinationToken($tokens);

        $profile = $graph->get($destinationExternalId, [
            'fields' => 'followers_count,media_count',
            'access_token' => $token,
        ], 'leer la cuenta de Instagram');

        $insights = $graph->insights($destinationExternalId, ['reach', 'views', 'total_interactions'], [
            'period' => 'day',
            'metric_type' => 'total_value',
        ], $token);

        return new AccountMetrics(
            followers: (int) ($profile['followers_count'] ?? 0),
            reach: $insights['reach'],
            impressions: $insights['views'],
            engagement: $insights['total_interactions'],
            postsCount: (int) ($profile['media_count'] ?? 0),
        );
    }

    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        $graph = MetaGraph::fromCredentials($credentials);
        $token = $this->destinationToken($tokens);

        $media = $graph->get($remoteId, [
            'fields' => 'like_count,comments_count',
            'access_token' => $token,
        ], 'leer la publicación de Instagram');

        $insights = $graph->insights($remoteId, ['views', 'reach', 'shares'], [], $token);

        return new PostMetrics(
            impressions: $insights['views'],
            reach: $insights['reach'],
            likes: (int) ($media['like_count'] ?? 0),
            comments: (int) ($media['comments_count'] ?? 0),
            shares: $insights['shares'],
            clicks: 0,
        );
    }

    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $graph = MetaGraph::fromCredentials($credentials);

        $media = $graph->get($destinationExternalId . '/media', [
            'fields' => 'id,comments.limit(25){id,text,timestamp,username,from}',
            'limit' => 25,
            'access_token' => $this->destinationToken($tokens),
        ], 'leer los comentarios de Instagram');

        $threads = [];
        foreach ((array) ($media['data'] ?? []) as $item) {
            foreach ((array) ($item['comments']['data'] ?? []) as $comment) {
                $authorId = (string) ($comment['from']['id'] ?? '');
                if ($authorId === $destinationExternalId) {
                    continue; // respuesta de la propia cuenta
                }
                $username = (string) ($comment['username'] ?? $comment['from']['username'] ?? 'usuario');
                $author = '@' . ltrim($username, '@');
                $sentAt = isset($comment['timestamp']) ? Carbon::parse((string) $comment['timestamp']) : Carbon::now();

                $threads[] = new InboxThread(
                    externalId: (string) $comment['id'],
                    type: 'comment',
                    participantName: $author,
                    participantExternalId: $authorId !== '' ? $authorId : $username,
                    lastMessageAt: $sentAt,
                    messages: [new InboxMessageData(
                        externalId: (string) $comment['id'],
                        authorName: $author,
                        authorExternalId: $authorId !== '' ? $authorId : $username,
                        body: (string) ($comment['text'] ?? ''),
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
        $reply = MetaGraph::fromCredentials($credentials)->post($conversationExternalId . '/replies', [
            'message' => $body,
            'access_token' => $this->destinationToken($tokens),
        ], 'responder el comentario de Instagram');

        return new InboxReplyResult((string) ($reply['id'] ?? ''));
    }

    /**
     * @param  array<string, string>  $params
     */
    private function createContainer(MetaGraph $graph, string $account, string $token, array $params): string
    {
        $container = $graph->post($account . '/media', [
            ...$params,
            'access_token' => $token,
        ], 'preparar el contenido de Instagram');

        $id = (string) ($container['id'] ?? '');
        if ($id === '') {
            throw new SocialProviderException('Instagram no devolvió el contenedor de la publicación.');
        }

        return $id;
    }

    /**
     * Los videos se procesan de forma asíncrona: se consulta `status_code` hasta
     * FINISHED. Si no termina a tiempo, el job de publicación reintentará.
     */
    private function waitUntilReady(MetaGraph $graph, string $container, string $token): void
    {
        for ($attempt = 1; $attempt <= self::STATUS_ATTEMPTS; $attempt++) {
            $status = $graph->get($container, [
                'fields' => 'status_code,status',
                'access_token' => $token,
            ], 'consultar el procesamiento del archivo');

            $code = (string) ($status['status_code'] ?? '');
            if ($code === 'FINISHED' || $code === 'PUBLISHED') {
                return;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new SocialProviderException(
                    'Instagram no pudo procesar el archivo: ' . (string) ($status['status'] ?? $code),
                );
            }

            Sleep::for(self::STATUS_INTERVAL_SECONDS)->seconds();
        }

        throw new SocialProviderException('Instagram sigue procesando el video; se reintentará la publicación.');
    }

    private function permalink(MetaGraph $graph, string $mediaId, string $token): string
    {
        try {
            $media = $graph->get($mediaId, ['fields' => 'permalink', 'access_token' => $token]);

            return (string) ($media['permalink'] ?? 'https://www.instagram.com/');
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            return 'https://www.instagram.com/';
        }
    }
}
