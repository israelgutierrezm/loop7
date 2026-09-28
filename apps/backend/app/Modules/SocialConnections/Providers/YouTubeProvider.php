<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\SocialConnections\Contracts\AccountMetrics;
use App\Modules\SocialConnections\Contracts\HasPublishingLimits;
use App\Modules\SocialConnections\Contracts\InboxMessageData;
use App\Modules\SocialConnections\Contracts\InboxReplyResult;
use App\Modules\SocialConnections\Contracts\InboxThread;
use App\Modules\SocialConnections\Contracts\MediaFile;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PostMetrics;
use App\Modules\SocialConnections\Contracts\ProvidesPublishOptions;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Contracts\PublishResult;
use App\Modules\SocialConnections\Contracts\RemoteAccount;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Providers\OAuth2\AbstractOAuth2Provider;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * YouTube (Data API v3 + OAuth de Google, docs/06). Sube un video por
 * publicación con el protocolo reanudable (un reintento continúa la subida
 * donde quedó); YouTube lo clasifica como Short si es vertical o cuadrado y
 * dura hasta 3 min (no hay marca en la API). Lee estadísticas del canal y de
 * los videos, y trae los comentarios al inbox.
 *
 * Las directrices de YouTube obligan a que la persona elija el título, la
 * privacidad y si es «contenido para niños», y a mostrar el aviso de
 * certificación: se piden en el editor (ProvidesPublishOptions). Los proyectos
 * de Google sin auditar suben los videos bloqueados en privado.
 */
class YouTubeProvider extends AbstractOAuth2Provider implements HasPublishingLimits, ProvidesPublishOptions
{
    private const API = 'https://www.googleapis.com/youtube/v3/';

    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public const PRIVACY_STATUSES = ['public', 'unlisted', 'private'];

    /** Categoría «Personas y blogs», válida en todas las regiones. */
    private const DEFAULT_CATEGORY = '22';

    private const TITLE_MAX = 100;

    private const DESCRIPTION_MAX_BYTES = 5000;

    /** Las sesiones de subida duran «un tiempo finito»: se reutilizan hasta 6 h. */
    private const SESSION_TTL_SECONDS = 6 * 3600;

    private const CHECKPOINT_SESSION = 'youtube.session';

    private const CHECKPOINT_VIDEO = 'youtube.video_id';

    public function key(): string
    {
        return 'youtube';
    }

    protected function displayName(): string
    {
        return 'YouTube';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function extraAuthorizeParams(): array
    {
        // offline + consent: Google sólo entrega el refresh token si se piden así.
        return ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'];
    }

    public function capabilities(): array
    {
        return [
            Capability::TEXT => false,
            Capability::IMAGE => false,
            Capability::VIDEO => true,
            Capability::SHORT_VIDEO => true,
            Capability::COMMENTS_READ => true,
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function publishingLimits(): array
    {
        return ['text' => self::DESCRIPTION_MAX_BYTES, 'media' => 1, 'videos' => 1];
    }

    public function defaultScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
            // Leer y responder comentarios sólo admite este scope.
            'https://www.googleapis.com/auth/youtube.force-ssl',
        ];
    }

    public function fetchAccount(OAuthTokens $tokens, array $credentials): RemoteAccount
    {
        $channel = $this->channels($tokens)[0];

        return new RemoteAccount((string) $channel['id'], (string) ($channel['snippet']['title'] ?? 'Canal de YouTube'));
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        return array_map(fn (array $channel) => new RemoteDestination(
            externalId: (string) $channel['id'],
            name: (string) ($channel['snippet']['title'] ?? 'Canal de YouTube'),
            type: 'channel',
            capabilities: $this->capabilities(),
            metadata: array_filter([
                'custom_url' => $channel['snippet']['customUrl'] ?? null,
                'thumbnail' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
            ]),
        ), $this->channels($tokens));
    }

    public function publishOptions(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $channel = $this->optionalChannel($tokens, $destinationExternalId);

        return [
            'channel_title' => (string) ($channel['snippet']['title'] ?? ''),
            'privacy_status_options' => self::PRIVACY_STATUSES,
            'title_max' => self::TITLE_MAX,
            'can_post' => true,
            'blocked_reason' => null,
        ];
    }

    public function optionErrors(array $options): array
    {
        $errors = [];
        if (! in_array($options['privacy_status'] ?? null, self::PRIVACY_STATUSES, true)) {
            $errors[] = 'Elige la privacidad del video en YouTube (público, oculto o privado).';
        }
        if (! is_bool($options['made_for_kids'] ?? null)) {
            $errors[] = 'Indica si el video de YouTube está hecho para niños.';
        }
        $title = (string) ($options['title'] ?? '');
        if (mb_strlen($title) > self::TITLE_MAX || preg_match('/[<>]/', $title) === 1) {
            $errors[] = 'El título de YouTube admite hasta 100 caracteres, sin < ni >.';
        }

        return $errors;
    }

    public function publish(OAuthTokens $tokens, string $destinationExternalId, PublishPayload $payload, array $credentials): PublishResult
    {
        if (($problems = $this->optionErrors($payload->options)) !== []) {
            throw new SocialProviderException(implode(' ', $problems));
        }
        $video = $payload->mediaFiles[0] ?? null;
        if (! $video instanceof MediaFile || ! $video->isVideo() || count($payload->mediaFiles) !== 1) {
            throw new SocialProviderException('YouTube publica exactamente un video por publicación.');
        }

        $checkpoint = $payload->checkpoint;
        $done = (string) $checkpoint->get(self::CHECKPOINT_VIDEO, '');
        if ($done !== '') {
            return $this->result($done);
        }

        $token = $this->accessToken($tokens);
        [$session, $resumed] = $this->session($token, $video, $payload, $checkpoint);
        // Sólo una sesión retomada puede tener bytes recibidos (o estar terminada).
        $offset = $resumed ? $this->uploadedBytes($token, $session, $video) : 0;

        if (is_string($offset)) {
            return $this->finish($offset, $checkpoint); // la subida ya había terminado
        }

        // PUT con el resto del archivo (todo, si la sesión es nueva). LimitStream
        // conserva el desplazamiento aunque el cliente HTTP rebobine el cuerpo.
        $body = new LimitStream(Utils::streamFor($video->open()), $video->sizeBytes - $offset, $offset);
        $headers = ['Content-Type' => $video->mimeType];
        if ($offset > 0) {
            $headers['Content-Range'] = 'bytes ' . $offset . '-' . ($video->sizeBytes - 1) . '/' . $video->sizeBytes;
        }

        $response = $this->send('subir el video', fn () => Http::withToken($token)
            ->timeout(1800)
            ->withHeaders($headers)
            ->withBody($body, $video->mimeType)
            ->put($session));

        if ($response->status() === 404) {
            $checkpoint->forget(self::CHECKPOINT_SESSION); // sesión caducada: el reintento empieza otra
        }
        $data = $this->json($response, 'subir el video');

        return $this->finish((string) ($data['id'] ?? ''), $checkpoint);
    }

    public function fetchAccountMetrics(OAuthTokens $tokens, string $destinationExternalId, array $credentials): AccountMetrics
    {
        $channel = $this->optionalChannel($tokens, $destinationExternalId);
        $stats = (array) ($channel['statistics'] ?? []);

        return new AccountMetrics(
            followers: (int) ($stats['subscriberCount'] ?? 0),
            impressions: (int) ($stats['viewCount'] ?? 0),
            postsCount: (int) ($stats['videoCount'] ?? 0),
        );
    }

    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        $data = $this->json($this->send('leer las estadísticas del video', fn () => $this->api($this->accessToken($tokens))
            ->get(self::API . 'videos', ['part' => 'statistics', 'id' => $remoteId])), 'leer las estadísticas del video');
        $stats = (array) ($data['items'][0]['statistics'] ?? []);

        return new PostMetrics(
            impressions: (int) ($stats['viewCount'] ?? 0),
            likes: (int) ($stats['likeCount'] ?? 0),
            comments: (int) ($stats['commentCount'] ?? 0),
        );
    }

    /**
     * Comentarios recientes de los videos del canal (sin los del propio canal).
     */
    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $data = $this->json($this->send('leer los comentarios', fn () => $this->api($this->accessToken($tokens))
            ->get(self::API . 'commentThreads', [
                'part' => 'snippet',
                'allThreadsRelatedToChannelId' => $destinationExternalId,
                'maxResults' => 25,
                'order' => 'time',
                'textFormat' => 'plainText',
            ])), 'leer los comentarios');

        $threads = [];
        foreach ((array) ($data['items'] ?? []) as $thread) {
            $comment = (array) ($thread['snippet']['topLevelComment'] ?? []);
            $snippet = (array) ($comment['snippet'] ?? []);
            $authorId = (string) ($snippet['authorChannelId']['value'] ?? '');
            if (! isset($comment['id']) || $authorId === $destinationExternalId || ($thread['snippet']['canReply'] ?? true) === false) {
                continue;
            }
            $author = (string) ($snippet['authorDisplayName'] ?? 'Usuario de YouTube');
            $sentAt = isset($snippet['publishedAt']) ? Carbon::parse((string) $snippet['publishedAt']) : Carbon::now();

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
                    body: (string) ($snippet['textDisplay'] ?? ''),
                    direction: 'inbound',
                    sentAt: $sentAt,
                )],
            );
        }

        return $threads;
    }

    public function replyToConversation(OAuthTokens $tokens, string $conversationExternalId, string $body, array $credentials): InboxReplyResult
    {
        $data = $this->json($this->send('responder el comentario', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::API . 'comments?part=snippet', [
                'snippet' => ['parentId' => $conversationExternalId, 'textOriginal' => $body],
            ])), 'responder el comentario');

        return new InboxReplyResult((string) ($data['id'] ?? ''));
    }

    protected function isTokenError(Response $response): bool
    {
        return $response->status() === 401;
    }

    /**
     * Sesión de subida reanudable: la del intento anterior si sigue vigente, o
     * una nueva con los metadatos elegidos.
     *
     * @return array{0: string, 1: bool}  [URI de la sesión, retomada]
     */
    private function session(string $token, MediaFile $video, PublishPayload $payload, PublishCheckpoint $checkpoint): array
    {
        $saved = $checkpoint->get(self::CHECKPOINT_SESSION);
        if (is_array($saved)
            && ($saved['size'] ?? null) === $video->sizeBytes
            && Carbon::now()->getTimestamp() - (int) ($saved['at'] ?? 0) < self::SESSION_TTL_SECONDS
        ) {
            return [(string) $saved['uri'], true];
        }

        $options = $payload->options;
        $metadata = [
            'snippet' => [
                'title' => $this->title($options, $payload),
                'description' => mb_strcut(str_replace(['<', '>'], '', $payload->body), 0, self::DESCRIPTION_MAX_BYTES),
                'categoryId' => self::DEFAULT_CATEGORY,
            ],
            'status' => [
                'privacyStatus' => $options['privacy_status'],
                'selfDeclaredMadeForKids' => $options['made_for_kids'],
                'containsSyntheticMedia' => ($options['synthetic_media'] ?? false) === true,
            ],
        ];

        $response = $this->send('preparar la subida', fn () => $this->api($token)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $video->sizeBytes,
                'X-Upload-Content-Type' => $video->mimeType,
            ])
            ->asJson()
            ->post(self::UPLOAD . '?uploadType=resumable&part=snippet,status', $metadata));
        $this->json($response, 'preparar la subida');

        $uri = $response->header('Location');
        if ($uri === '') {
            throw new SocialProviderException('YouTube no devolvió la dirección de subida.');
        }
        $checkpoint->put(self::CHECKPOINT_SESSION, ['uri' => $uri, 'size' => $video->sizeBytes, 'at' => Carbon::now()->getTimestamp()]);

        return [$uri, false];
    }

    /**
     * Bytes ya recibidos en la sesión (0 si es nueva) o, si la subida ya había
     * terminado, el id del video.
     */
    private function uploadedBytes(string $token, string $session, MediaFile $video): int|string
    {
        $response = $this->send('consultar la subida', fn () => Http::withToken($token)
            ->timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['Content-Range' => 'bytes */' . $video->sizeBytes])
            ->withBody('', $video->mimeType)
            ->put($session));

        if ($response->status() === 308) {
            // «Range: bytes=0-LAST»; sin cabecera, no se recibió nada.
            return preg_match('/bytes=\d+-(\d+)/', $response->header('Range'), $m) === 1 ? (int) $m[1] + 1 : 0;
        }
        if ($response->status() === 200 || $response->status() === 201) {
            return (string) ($response->json('id') ?? '');
        }

        return 0; // sesión nueva o no consultable: se sube desde el principio
    }

    private function finish(string $videoId, PublishCheckpoint $checkpoint): PublishResult
    {
        if ($videoId === '') {
            throw new SocialProviderException('YouTube no devolvió el identificador del video.');
        }
        $checkpoint->put(self::CHECKPOINT_VIDEO, $videoId);

        return $this->result($videoId);
    }

    private function result(string $videoId): PublishResult
    {
        return new PublishResult($videoId, 'https://www.youtube.com/watch?v=' . $videoId);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function title(array $options, PublishPayload $payload): string
    {
        $candidates = [(string) ($options['title'] ?? ''), $payload->title, (string) strtok(trim($payload->body), "\n")];
        foreach ($candidates as $candidate) {
            $clean = trim(str_replace(['<', '>'], '', $candidate));
            if ($clean !== '') {
                return Str::limit($clean, self::TITLE_MAX - 3);
            }
        }

        return 'Video';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channels(OAuthTokens $tokens): array
    {
        $data = $this->json($this->send('leer tu canal', fn () => $this->api($this->accessToken($tokens))
            ->get(self::API . 'channels', ['part' => 'snippet,statistics', 'mine' => 'true'])), 'leer tu canal');

        $items = array_values(array_filter((array) ($data['items'] ?? []), fn ($c) => is_array($c) && isset($c['id'])));
        if ($items === []) {
            throw new SocialProviderException('La cuenta de Google no tiene un canal de YouTube: créalo en youtube.com y vuelve a conectar.');
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionalChannel(OAuthTokens $tokens, string $channelId): array
    {
        $data = $this->json($this->send('leer el canal', fn () => $this->api($this->accessToken($tokens))
            ->get(self::API . 'channels', ['part' => 'snippet,statistics', 'id' => $channelId])), 'leer el canal');

        return (array) ($data['items'][0] ?? []);
    }
}
