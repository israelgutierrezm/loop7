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

/**
 * Adaptador de Meta para Páginas de Facebook (Graph API). Publica texto/enlace,
 * una o varias imágenes y video; lee métricas vigentes (views/reach tras la
 * retirada de "impressions" en nov-2025) y gestiona comentarios del inbox.
 */
class FacebookProvider extends AbstractMetaProvider
{
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
            Capability::LINK => true,
            Capability::COMMENTS_READ => true,
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function defaultScopes(): array
    {
        return [
            'public_profile',
            'pages_show_list',
            'pages_read_engagement',
            'pages_read_user_content',
            'pages_manage_posts',
            'pages_manage_engagement',
            'read_insights',
        ];
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $pages = MetaGraph::fromCredentials($credentials)->get('me/accounts', [
            'fields' => 'id,name,category,access_token',
            'limit' => 100,
            'access_token' => $tokens->accessToken,
        ], 'listar tus páginas');

        return collect((array) ($pages['data'] ?? []))
            ->filter(fn ($page) => is_array($page) && isset($page['id']))
            ->map(fn (array $page) => new RemoteDestination(
                externalId: (string) $page['id'],
                name: (string) ($page['name'] ?? 'Página'),
                type: 'page',
                capabilities: $this->capabilities(),
                metadata: ['category' => $page['category'] ?? null],
                accessToken: isset($page['access_token']) ? (string) $page['access_token'] : null,
            ))
            ->values()
            ->all();
    }

    public function publish(
        OAuthTokens $tokens,
        string $destinationExternalId,
        PublishPayload $payload,
        array $credentials,
    ): PublishResult {
        $graph = MetaGraph::fromCredentials($credentials);
        $token = $this->pageToken($graph, $destinationExternalId, $tokens);
        $page = $destinationExternalId;

        if ($payload->hasVideo()) {
            // Un video por publicación: se publica el primero con el texto como descripción.
            $index = (int) array_search('video', $payload->mediaTypes, true);
            $video = $graph->post($page . '/videos', [
                'file_url' => $payload->mediaUrls[$index],
                'description' => $payload->body,
                'access_token' => $token,
            ], 'publicar el video');
            $remoteId = (string) ($video['id'] ?? '');

            return new PublishResult($remoteId, 'https://www.facebook.com/' . $remoteId);
        }

        if (count($payload->mediaUrls) === 1) {
            $photo = $graph->post($page . '/photos', [
                'url' => $payload->mediaUrls[0],
                'caption' => $payload->body,
                'access_token' => $token,
            ], 'publicar la imagen');
            $remoteId = (string) ($photo['post_id'] ?? $photo['id'] ?? '');

            return new PublishResult($remoteId, 'https://www.facebook.com/' . $remoteId);
        }

        $form = ['message' => $payload->body, 'access_token' => $token];

        if (count($payload->mediaUrls) > 1) {
            // Varias imágenes: se suben sin publicar y se adjuntan a una sola publicación.
            foreach ($payload->mediaUrls as $i => $url) {
                $photo = $graph->post($page . '/photos', [
                    'url' => $url,
                    'published' => 'false',
                    'access_token' => $token,
                ], 'subir las imágenes');
                $form['attached_media[' . $i . ']'] = (string) json_encode(['media_fbid' => (string) ($photo['id'] ?? '')]);
            }
        } elseif (preg_match('~https?://\S+~u', $payload->body, $match) === 1) {
            // Texto con enlace: se publica como enlace para generar la vista previa.
            $form['link'] = $match[0];
        }

        $post = $graph->post($page . '/feed', $form, 'publicar en la página');
        $remoteId = (string) ($post['id'] ?? '');

        return new PublishResult($remoteId, 'https://www.facebook.com/' . $remoteId);
    }

    public function fetchAccountMetrics(
        OAuthTokens $tokens,
        string $destinationExternalId,
        array $credentials,
    ): AccountMetrics {
        $graph = MetaGraph::fromCredentials($credentials);
        $token = $this->pageToken($graph, $destinationExternalId, $tokens);

        $page = $graph->get($destinationExternalId, [
            'fields' => 'followers_count,fan_count',
            'access_token' => $token,
        ], 'leer la página');

        $insights = $graph->insights($destinationExternalId, [
            'page_media_view',
            'page_total_media_view_unique',
            'page_post_engagements',
        ], ['period' => 'day'], $token);

        return new AccountMetrics(
            followers: (int) ($page['followers_count'] ?? $page['fan_count'] ?? 0),
            reach: $insights['page_total_media_view_unique'],
            impressions: $insights['page_media_view'],
            engagement: $insights['page_post_engagements'],
            postsCount: 0,
        );
    }

    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        $graph = MetaGraph::fromCredentials($credentials);
        $token = $this->pageToken($graph, $this->pageIdFromObjectId($remoteId), $tokens);

        $post = $graph->get($remoteId, [
            'fields' => 'reactions.summary(true).limit(0),comments.summary(true).limit(0),shares',
            'access_token' => $token,
        ], 'leer la publicación');

        $insights = $graph->insights($remoteId, [
            'post_media_view',
            'post_total_media_view_unique',
            'post_clicks',
        ], [], $token);

        return new PostMetrics(
            impressions: $insights['post_media_view'],
            reach: $insights['post_total_media_view_unique'],
            likes: (int) ($post['reactions']['summary']['total_count'] ?? 0),
            comments: (int) ($post['comments']['summary']['total_count'] ?? 0),
            shares: (int) ($post['shares']['count'] ?? 0),
            clicks: $insights['post_clicks'],
        );
    }

    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $graph = MetaGraph::fromCredentials($credentials);
        $token = $this->pageToken($graph, $destinationExternalId, $tokens);

        $feed = $graph->get($destinationExternalId . '/feed', [
            'fields' => 'id,comments.limit(25){id,message,created_time,from}',
            'limit' => 25,
            'access_token' => $token,
        ], 'leer los comentarios');

        $threads = [];
        foreach ((array) ($feed['data'] ?? []) as $post) {
            foreach ((array) ($post['comments']['data'] ?? []) as $comment) {
                $authorId = (string) ($comment['from']['id'] ?? '');
                if ($authorId === $destinationExternalId) {
                    continue; // respuesta de la propia página
                }
                $author = (string) ($comment['from']['name'] ?? 'Usuario de Facebook');
                $sentAt = isset($comment['created_time']) ? Carbon::parse((string) $comment['created_time']) : Carbon::now();

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
        // El id del comentario no identifica la página: se usa el token del destino.
        $reply = MetaGraph::fromCredentials($credentials)->post($conversationExternalId . '/comments', [
            'message' => $body,
            'access_token' => $this->destinationToken($tokens),
        ], 'responder el comentario');

        return new InboxReplyResult((string) ($reply['id'] ?? ''));
    }

    /**
     * Page token: el guardado (cifrado) del destino; si no existe (conexión
     * manual con token de usuario), se deriva al vuelo y, si tampoco es posible,
     * se usa el token tal cual (puede ser ya un page token).
     */
    private function pageToken(MetaGraph $graph, string $pageId, OAuthTokens $tokens): string
    {
        if ($tokens->destinationToken !== null && $tokens->destinationToken !== '') {
            return $tokens->destinationToken;
        }

        $accountToken = $this->accountToken($tokens);

        try {
            $page = $graph->get($pageId, ['fields' => 'access_token', 'access_token' => $accountToken]);
            $token = (string) ($page['access_token'] ?? '');

            return $token !== '' ? $token : $accountToken;
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            return $accountToken;
        }
    }

    /**
     * Los ids de publicaciones de Página tienen la forma {pageId}_{postId}.
     */
    private function pageIdFromObjectId(string $objectId): string
    {
        return str_contains($objectId, '_') ? explode('_', $objectId)[0] : $objectId;
    }
}
