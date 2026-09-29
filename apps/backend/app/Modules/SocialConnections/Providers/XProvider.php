<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\SocialConnections\Contracts\AccountMetrics;
use App\Modules\SocialConnections\Contracts\CountsText;
use App\Modules\SocialConnections\Contracts\HasPlanQuota;
use App\Modules\SocialConnections\Contracts\HasPublishingLimits;
use App\Modules\SocialConnections\Contracts\InboxMessageData;
use App\Modules\SocialConnections\Contracts\InboxReplyResult;
use App\Modules\SocialConnections\Contracts\InboxThread;
use App\Modules\SocialConnections\Contracts\MediaFile;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PostMetrics;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Contracts\PublishResult;
use App\Modules\SocialConnections\Contracts\RemoteAccount;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Contracts\RevokesAccess;
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\OAuth2\AbstractOAuth2Provider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * X (API v2, docs/06). OAuth 2.0 con PKCE y cliente confidencial (Basic); el
 * token dura 2 h y el refresh token es de un solo uso (se renueva con bloqueo,
 * ver SocialConnectionService). Publica texto (280 caracteres ponderados),
 * hasta 4 imágenes o un video/GIF (subida por partes de la API v2), lee
 * métricas y trae las menciones al inbox.
 *
 * Desde 2026 la API es de pago por uso: cada publicación y cada archivo subido
 * se cobran (más si el texto lleva una URL) y, fuera de Enterprise, sólo se
 * puede responder a quien menciona a la cuenta.
 */
class XProvider extends AbstractOAuth2Provider implements CountsText, HasPlanQuota, HasPublishingLimits, RevokesAccess
{
    private const API = 'https://api.x.com/2/';

    private const SEGMENT_BYTES = 4 * 1024 * 1024;

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /** Espera al procesamiento del video: hasta 60 consultas (respetando check_after_secs). */
    private const STATUS_CHECKS = 60;

    /** Los archivos subidos caducan a las 24 h: se reutilizan hasta las 23. */
    private const MEDIA_TTL_SECONDS = 23 * 3600;

    private const CHECKPOINT_MEDIA = 'x.media';

    private const CHECKPOINT_POST = 'x.post_id';

    public function key(): string
    {
        return 'x';
    }

    protected function displayName(): string
    {
        return 'X';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://x.com/i/oauth2/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return self::API . 'oauth2/token';
    }

    protected function usesBasicAuth(): bool
    {
        return true;
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

    public function publishingLimits(): array
    {
        return ['text' => 280, 'images' => 4, 'videos' => 1];
    }

    /**
     * X cobra a la plataforma cada publicación: cada organización tiene un cupo mensual.
     */
    public function planQuota(): array
    {
        return ['entitlement' => Entitlement::X_POSTS_MONTH, 'period' => 'month', 'label' => 'publicaciones en X al mes'];
    }

    public function defaultScopes(): array
    {
        return ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'];
    }

    /**
     * Longitud ponderada de X: cada URL cuenta 23 y los caracteres fuera de los
     * rangos latinos/puntuación (emojis, CJK…) cuentan 2.
     */
    public function textLength(string $text): int
    {
        $text = (string) (class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_C) : $text);
        $length = 0;
        $withoutUrls = (string) preg_replace_callback('~https?://\S+~iu', function () use (&$length): string {
            $length += 23;

            return '';
        }, $text);

        preg_match_all('/\X/u', $withoutUrls, $graphemes);
        foreach ($graphemes[0] as $grapheme) {
            $codepoints = mb_str_split($grapheme);
            $light = count($codepoints) === 1 && $this->isLight(mb_ord($codepoints[0]));
            $length += $light ? 1 : 2;
        }

        return $length;
    }

    public function revokeAccess(OAuthTokens $tokens, array $credentials): void
    {
        $this->revokeAt(self::API . 'oauth2/revoke', $tokens, $credentials);
    }

    public function fetchAccount(OAuthTokens $tokens, array $credentials): RemoteAccount
    {
        $me = $this->me($tokens);

        return new RemoteAccount((string) ($me['id'] ?? ''), '@' . ($me['username'] ?? 'cuenta'));
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $me = $this->me($tokens);

        return [new RemoteDestination(
            externalId: (string) ($me['id'] ?? ''),
            name: '@' . ($me['username'] ?? 'cuenta'),
            type: 'profile',
            capabilities: $this->capabilities(),
            metadata: array_filter([
                'username' => $me['username'] ?? null,
                'name' => $me['name'] ?? null,
                'profile_image_url' => $me['profile_image_url'] ?? null,
            ]),
        )];
    }

    public function publish(OAuthTokens $tokens, string $destinationExternalId, PublishPayload $payload, array $credentials): PublishResult
    {
        $checkpoint = $payload->checkpoint;
        // Crear no es idempotente en X (y se cobra): una publicación ya creada no se repite.
        $existing = (string) $checkpoint->get(self::CHECKPOINT_POST, '');
        if ($existing !== '') {
            return new PublishResult($existing, $this->postUrl($existing));
        }

        $files = $payload->mediaFiles;
        $videos = count(array_filter($files, fn (MediaFile $f) => $f->isVideo() || $f->mimeType === 'image/gif'));
        if ($videos > 0 && count($files) > 1) {
            throw new SocialProviderException('X admite hasta 4 imágenes, o un solo video o GIF, por publicación.');
        }
        if (count($files) > 4) {
            throw new SocialProviderException('X admite hasta 4 imágenes por publicación.');
        }

        $post = ['text' => $payload->body];
        if ($files !== []) {
            $post['media'] = ['media_ids' => $this->uploadMedia($tokens, $files, $checkpoint)];
        }

        $data = $this->json($this->send('publicar', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::API . 'tweets', $post)), 'publicar');
        $id = (string) ($data['data']['id'] ?? '');
        if ($id === '') {
            throw new SocialProviderException('X no devolvió el identificador de la publicación.');
        }
        $checkpoint->put(self::CHECKPOINT_POST, $id);

        return new PublishResult($id, $this->postUrl($id));
    }

    public function fetchAccountMetrics(OAuthTokens $tokens, string $destinationExternalId, array $credentials): AccountMetrics
    {
        $metrics = (array) ($this->me($tokens)['public_metrics'] ?? []);

        return new AccountMetrics(
            followers: (int) ($metrics['followers_count'] ?? 0),
            postsCount: (int) ($metrics['tweet_count'] ?? $metrics['post_count'] ?? 0),
        );
    }

    /**
     * Métricas públicas y, en publicaciones de los últimos 30 días, las privadas
     * (impresiones y clics) que X sólo da al autor.
     */
    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        $token = $this->accessToken($tokens);
        try {
            $data = $this->json($this->send('leer la publicación', fn () => $this->api($token)
                ->get(self::API . 'tweets/' . $remoteId, ['tweet.fields' => 'public_metrics,non_public_metrics'])), 'leer la publicación');
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            $data = $this->json($this->send('leer la publicación', fn () => $this->api($token)
                ->get(self::API . 'tweets/' . $remoteId, ['tweet.fields' => 'public_metrics'])), 'leer la publicación');
        }

        $public = (array) ($data['data']['public_metrics'] ?? []);
        $private = (array) ($data['data']['non_public_metrics'] ?? []);

        return new PostMetrics(
            impressions: (int) ($private['impression_count'] ?? $public['impression_count'] ?? 0),
            likes: (int) ($public['like_count'] ?? 0),
            comments: (int) ($public['reply_count'] ?? 0),
            shares: (int) ($public['retweet_count'] ?? $public['repost_count'] ?? 0) + (int) ($public['quote_count'] ?? 0),
            clicks: (int) ($private['url_link_clicks'] ?? 0),
        );
    }

    /**
     * Menciones de los últimos 3 días (X cobra cada publicación leída, una vez
     * al día por publicación).
     */
    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $data = $this->json($this->send('leer las menciones', fn () => $this->api($this->accessToken($tokens))
            ->get(self::API . 'users/' . $destinationExternalId . '/mentions', [
                'max_results' => 20,
                'start_time' => Carbon::now()->subDays(3)->utc()->format('Y-m-d\TH:i:s\Z'),
                'tweet.fields' => 'created_at,author_id,conversation_id',
                'expansions' => 'author_id',
                'user.fields' => 'username,name',
            ])), 'leer las menciones');

        $users = [];
        foreach ((array) ($data['includes']['users'] ?? []) as $user) {
            $users[(string) ($user['id'] ?? '')] = (string) ($user['username'] ?? '');
        }

        $threads = [];
        foreach ((array) ($data['data'] ?? []) as $mention) {
            $id = (string) ($mention['id'] ?? '');
            $authorId = (string) ($mention['author_id'] ?? '');
            if ($id === '' || $authorId === $destinationExternalId) {
                continue;
            }
            $author = '@' . (($users[$authorId] ?? '') !== '' ? $users[$authorId] : $authorId);
            $sentAt = isset($mention['created_at']) ? Carbon::parse((string) $mention['created_at']) : Carbon::now();

            $threads[] = new InboxThread(
                externalId: $id,
                type: 'mention',
                participantName: $author,
                participantExternalId: $authorId,
                lastMessageAt: $sentAt,
                messages: [new InboxMessageData(
                    externalId: $id,
                    authorName: $author,
                    authorExternalId: $authorId,
                    body: (string) ($mention['text'] ?? ''),
                    direction: 'inbound',
                    sentAt: $sentAt,
                )],
            );
        }

        return $threads;
    }

    public function replyToConversation(OAuthTokens $tokens, string $conversationExternalId, string $body, array $credentials): InboxReplyResult
    {
        $data = $this->json($this->send('responder', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::API . 'tweets', ['text' => $body, 'reply' => ['in_reply_to_tweet_id' => $conversationExternalId]])), 'responder');

        return new InboxReplyResult((string) ($data['data']['id'] ?? ''));
    }

    protected function isTokenError(Response $response): bool
    {
        // Un token que X no reconoce llega como 403 «unsupported-authentication».
        return $response->status() === 401
            || str_ends_with((string) $response->json('type'), '/unsupported-authentication');
    }

    protected function errorMessage(Response $response): string
    {
        $detail = $response->json('detail') ?? $response->json('errors.0.message') ?? $response->json('errors.0.detail');

        return is_string($detail) && $detail !== '' ? $detail : parent::errorMessage($response);
    }

    /**
     * Sube los archivos (o reutiliza los subidos en un intento anterior que no
     * hayan caducado) y devuelve sus ids.
     *
     * @param  list<MediaFile>  $files
     * @return list<string>
     */
    private function uploadMedia(OAuthTokens $tokens, array $files, PublishCheckpoint $checkpoint): array
    {
        $done = (array) $checkpoint->get(self::CHECKPOINT_MEDIA, []);
        $ids = [];

        foreach ($files as $i => $file) {
            $saved = $done[$i] ?? null;
            if (is_array($saved) && Carbon::now()->getTimestamp() - (int) ($saved['at'] ?? 0) < self::MEDIA_TTL_SECONDS) {
                $ids[] = (string) $saved['id'];

                continue;
            }

            $id = ($file->isVideo() || $file->mimeType === 'image/gif')
                ? $this->uploadChunked($tokens, $file)
                : $this->uploadImage($tokens, $file);
            $done[$i] = ['id' => $id, 'at' => Carbon::now()->getTimestamp()];
            $checkpoint->put(self::CHECKPOINT_MEDIA, $done);
            $ids[] = $id;
        }

        return $ids;
    }

    private function uploadImage(OAuthTokens $tokens, MediaFile $file): string
    {
        if ($file->sizeBytes > self::IMAGE_MAX_BYTES) {
            throw new SocialProviderException('X admite imágenes de hasta 5 MB.');
        }

        $stream = $file->open();
        try {
            $contents = (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        $data = $this->json($this->send('subir la imagen', fn () => $this->api($this->accessToken($tokens))
            ->attach('media', $contents, 'imagen')
            ->post(self::API . 'media/upload', ['media_category' => 'tweet_image'])), 'subir la imagen');

        return $this->mediaId($data);
    }

    /**
     * Video o GIF: initialize → append (partes de 4 MB) → finalize → estado.
     */
    private function uploadChunked(OAuthTokens $tokens, MediaFile $file): string
    {
        $token = $this->accessToken($tokens);
        $init = $this->json($this->send('preparar el video', fn () => $this->api($token)
            ->asJson()
            ->post(self::API . 'media/upload/initialize', [
                'media_type' => $file->mimeType,
                'total_bytes' => $file->sizeBytes,
                'media_category' => $file->mimeType === 'image/gif' ? 'tweet_gif' : 'tweet_video',
            ])), 'preparar el video');
        $id = $this->mediaId($init);

        $stream = $file->open();
        try {
            for ($segment = 0; ! feof($stream); $segment++) {
                $chunk = (string) fread($stream, self::SEGMENT_BYTES);
                if ($chunk === '') {
                    break;
                }
                $this->json($this->send('subir el video', fn () => $this->api($token)
                    ->timeout(300)
                    ->attach('media', $chunk, 'parte')
                    ->post(self::API . 'media/upload/' . $id . '/append', ['segment_index' => (string) $segment])), 'subir el video');
            }
        } finally {
            fclose($stream);
        }

        $final = $this->json($this->send('terminar la subida', fn () => $this->api($token)
            ->post(self::API . 'media/upload/' . $id . '/finalize')), 'terminar la subida');

        $this->waitForProcessing($token, $id, (array) ($final['data']['processing_info'] ?? []));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function waitForProcessing(string $token, string $id, array $info): void
    {
        for ($i = 0; $i < self::STATUS_CHECKS && $info !== []; $i++) {
            $state = (string) ($info['state'] ?? '');
            if ($state === 'succeeded') {
                return;
            }
            if ($state === 'failed') {
                throw new SocialProviderException('X no pudo procesar el video: ' . ($info['error']['message'] ?? 'formato o duración no admitidos') . '.');
            }

            Sleep::for(max(1, min(30, (int) ($info['check_after_secs'] ?? 5))))->seconds();
            $status = $this->json($this->send('consultar el video', fn () => $this->api($token)
                ->get(self::API . 'media/upload', ['command' => 'STATUS', 'media_id' => $id])), 'consultar el video');
            $info = (array) ($status['data']['processing_info'] ?? []);
        }

        if ($info !== [] && ($info['state'] ?? '') !== 'succeeded') {
            throw new SocialProviderException('X sigue procesando el video; se reintentará con el mismo archivo.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mediaId(array $data): string
    {
        $id = (string) ($data['data']['id'] ?? '');
        if ($id === '') {
            throw new SocialProviderException('X no devolvió el identificador del archivo.');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function me(OAuthTokens $tokens): array
    {
        $data = $this->json($this->send('leer tu cuenta', fn () => $this->api($this->accessToken($tokens))
            ->get(self::API . 'users/me', ['user.fields' => 'profile_image_url,public_metrics'])), 'leer tu cuenta');

        return (array) ($data['data'] ?? []);
    }

    private function postUrl(string $id): string
    {
        return 'https://x.com/i/web/status/' . $id;
    }

    /**
     * Rangos que X cuenta como 1 (latín, puntuación general…).
     */
    private function isLight(int $codepoint): bool
    {
        return $codepoint <= 4351
            || ($codepoint >= 8192 && $codepoint <= 8205)
            || ($codepoint >= 8208 && $codepoint <= 8223)
            || ($codepoint >= 8242 && $codepoint <= 8247);
    }
}
