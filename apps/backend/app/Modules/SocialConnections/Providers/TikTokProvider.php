<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\SocialConnections\Contracts\AccountMetrics;
use App\Modules\SocialConnections\Contracts\HasPublishingLimits;
use App\Modules\SocialConnections\Contracts\InboxReplyResult;
use App\Modules\SocialConnections\Contracts\MediaFile;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PostMetrics;
use App\Modules\SocialConnections\Contracts\ProvidesPublishOptions;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * TikTok (Login Kit v2 + Content Posting API «Direct Post», docs/06). Publica
 * un video por publicación subiéndolo por partes (FILE_UPLOAD; las fotos sólo
 * admiten descarga desde un dominio verificado). Lee métricas de la cuenta y
 * de los videos públicos. TikTok no ofrece comentarios a apps comerciales.
 *
 * Sus directrices obligan a que la persona elija la privacidad (sin valor por
 * defecto), las interacciones y el aviso de contenido comercial, y acepte la
 * confirmación de uso de música: se piden en el editor (ProvidesPublishOptions)
 * y se revalidan contra la cuenta al publicar. Mientras la app no supere la
 * auditoría de TikTok, todo lo publicado queda en privado («Solo yo»).
 *
 * Sin PKCE en web. Token de 24 h con refresh token de 365 días.
 */
class TikTokProvider extends AbstractOAuth2Provider implements HasPublishingLimits, ProvidesPublishOptions, RevokesAccess
{
    private const API = 'https://open.tiktokapis.com/v2/';

    public const PRIVACY_LEVELS = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

    /** Una sola parte hasta 64 MB; más grandes, partes de 10 MB (la última absorbe el resto). */
    private const SINGLE_CHUNK_MAX = 64_000_000;

    private const CHUNK_SIZE = 10_000_000;

    /** Espera al procesamiento: 40 consultas × 3 s (el límite es 30/min por token). */
    private const STATUS_CHECKS = 40;

    private const STATUS_INTERVAL_SECONDS = 3;

    private const CHECKPOINT_PUBLISH = 'tiktok.publish_id';

    /** Motivos de fallo de TikTok en palabras de la persona. */
    private const FAIL_REASONS = [
        'file_format_check_failed' => 'el formato del video no es válido (usa MP4, MOV o WebM)',
        'duration_check_failed' => 'la duración del video supera la permitida para esta cuenta',
        'frame_rate_check_failed' => 'la frecuencia de imagen no es válida (23–60 fps)',
        'picture_size_check_failed' => 'la resolución no es válida (360–4096 px)',
        'spam_risk_too_many_posts' => 'la cuenta alcanzó el límite diario de publicaciones',
        'spam_risk_user_banned_from_posting' => 'TikTok no permite publicar a esta cuenta ahora mismo',
        'spam_risk_text' => 'TikTok marcó el texto como posible spam',
        'spam_risk' => 'TikTok marcó la publicación como posible spam',
        'auth_removed' => 'la cuenta retiró el acceso a la app',
        'internal' => 'error interno de TikTok, vuelve a intentarlo',
    ];

    public function key(): string
    {
        return 'tiktok';
    }

    protected function displayName(): string
    {
        return 'TikTok';
    }

    public function usesPkce(): bool
    {
        return false;
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/';
    }

    protected function tokenEndpoint(): string
    {
        return self::API . 'oauth/token/';
    }

    protected function clientIdParam(): string
    {
        return 'client_key';
    }

    protected function scopeSeparator(): string
    {
        return ',';
    }

    public function capabilities(): array
    {
        return [
            Capability::TEXT => false,
            Capability::IMAGE => false,
            Capability::VIDEO => true,
            Capability::SHORT_VIDEO => true,
            Capability::COMMENTS_READ => false,
            Capability::COMMENTS_REPLY => false,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function publishingLimits(): array
    {
        return ['text' => 2200, 'media' => 1, 'videos' => 1];
    }

    public function defaultScopes(): array
    {
        return ['user.info.basic', 'user.info.stats', 'video.publish', 'video.list'];
    }

    /**
     * TikTok revoca con el token de acceso (dura 24 h): si caducó, se renueva antes.
     */
    public function revokeAccess(OAuthTokens $tokens, array $credentials): void
    {
        if ($tokens->refreshToken !== null && ($tokens->accessToken === '' || $tokens->expiresAt?->isPast())) {
            $tokens = $this->refreshTokens($tokens->refreshToken, $credentials);
        }

        $this->revokeAt(self::API . 'oauth/revoke/', new OAuthTokens($tokens->accessToken), $credentials);
    }

    public function fetchAccount(OAuthTokens $tokens, array $credentials): RemoteAccount
    {
        $user = $this->user($tokens, ['open_id', 'display_name']);

        return new RemoteAccount((string) ($user['open_id'] ?? ''), (string) ($user['display_name'] ?? 'Cuenta de TikTok'));
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $user = $this->user($tokens, ['open_id', 'display_name', 'avatar_url']);

        return [new RemoteDestination(
            externalId: (string) ($user['open_id'] ?? ''),
            name: (string) ($user['display_name'] ?? 'Cuenta de TikTok'),
            type: 'profile',
            capabilities: $this->capabilities(),
            metadata: array_filter(['avatar_url' => $user['avatar_url'] ?? null]),
        )];
    }

    /**
     * Datos de la cuenta que TikTok obliga a mostrar al publicar (nombre,
     * privacidades posibles, interacciones desactivadas, duración máxima) y si
     * puede publicar ahora.
     */
    public function publishOptions(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        [$data, $blocked] = $this->creatorInfo($tokens);

        return [
            'creator_nickname' => (string) ($data['creator_nickname'] ?? ''),
            'creator_username' => (string) ($data['creator_username'] ?? ''),
            'creator_avatar_url' => $data['creator_avatar_url'] ?? null,
            'privacy_level_options' => array_values(array_intersect(self::PRIVACY_LEVELS, (array) ($data['privacy_level_options'] ?? []))),
            'comment_disabled' => (bool) ($data['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($data['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($data['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => (int) ($data['max_video_post_duration_sec'] ?? 0),
            'can_post' => $blocked === null,
            'blocked_reason' => $blocked,
        ];
    }

    public function optionErrors(array $options): array
    {
        $errors = [];
        $privacy = $options['privacy_level'] ?? null;
        if (! is_string($privacy) || ! in_array($privacy, self::PRIVACY_LEVELS, true)) {
            $errors[] = 'Elige quién puede ver la publicación en TikTok.';
        }
        if (($options['commercial'] ?? false) === true) {
            if (($options['brand_organic'] ?? false) !== true && ($options['brand_content'] ?? false) !== true) {
                $errors[] = 'Indica si el contenido de TikTok promociona tu marca, a un tercero o ambos.';
            }
            if (($options['brand_content'] ?? false) === true && $privacy === 'SELF_ONLY') {
                $errors[] = 'El contenido de marca en TikTok no puede ser privado («Solo yo»).';
            }
        }
        if (($options['consent'] ?? false) !== true) {
            $errors[] = 'Acepta la confirmación de uso de música de TikTok antes de programar.';
        }

        return $errors;
    }

    public function publish(OAuthTokens $tokens, string $destinationExternalId, PublishPayload $payload, array $credentials): PublishResult
    {
        $options = $payload->options;
        if (($problems = $this->optionErrors($options)) !== []) {
            throw new SocialProviderException(implode(' ', $problems));
        }

        $videos = array_values(array_filter($payload->mediaFiles, fn (MediaFile $f) => $f->isVideo()));
        if (count($videos) !== 1 || count($payload->mediaFiles) !== 1) {
            throw new SocialProviderException('TikTok publica exactamente un video por publicación.');
        }
        $video = $videos[0];
        $checkpoint = $payload->checkpoint;

        // Retoma una publicación ya iniciada: TikTok no ofrece clave de idempotencia.
        $publishId = (string) $checkpoint->get(self::CHECKPOINT_PUBLISH, '');
        if ($publishId !== '') {
            return $this->waitForPublication($tokens, $publishId, $checkpoint, $this->username($tokens));
        }

        // La cuenta debe poder publicar con la privacidad elegida (se consulta en vivo).
        [$creator, $blocked] = $this->creatorInfo($tokens);
        if ($blocked !== null) {
            throw new SocialProviderException('TikTok no permite publicar ahora: ' . $blocked);
        }
        if (! in_array($options['privacy_level'], (array) ($creator['privacy_level_options'] ?? []), true)) {
            throw new SocialProviderException('La privacidad elegida ya no está disponible para esta cuenta de TikTok: edita la publicación y elige otra.');
        }

        [$chunkSize, $chunks] = $this->chunking($video->sizeBytes);
        $init = $this->tiktok($this->send('preparar el video', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::API . 'post/publish/video/init/', [
                'post_info' => [
                    'title' => $payload->body,
                    'privacy_level' => $options['privacy_level'],
                    // Interacciones desmarcadas por defecto y bloqueadas si la cuenta las desactivó.
                    'disable_comment' => ($options['allow_comment'] ?? false) !== true || ($creator['comment_disabled'] ?? false),
                    'disable_duet' => ($options['allow_duet'] ?? false) !== true || ($creator['duet_disabled'] ?? false),
                    'disable_stitch' => ($options['allow_stitch'] ?? false) !== true || ($creator['stitch_disabled'] ?? false),
                    'brand_content_toggle' => ($options['commercial'] ?? false) === true && ($options['brand_content'] ?? false) === true,
                    'brand_organic_toggle' => ($options['commercial'] ?? false) === true && ($options['brand_organic'] ?? false) === true,
                    'is_aigc' => ($options['is_aigc'] ?? false) === true,
                ],
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => $video->sizeBytes,
                    'chunk_size' => $chunkSize,
                    'total_chunk_count' => $chunks,
                ],
            ])), 'preparar el video');

        $publishId = (string) ($init['publish_id'] ?? '');
        $uploadUrl = (string) ($init['upload_url'] ?? '');
        if ($publishId === '' || $uploadUrl === '') {
            throw new SocialProviderException('TikTok no devolvió dónde subir el video.');
        }
        // Antes de subir: si el worker muere, el reintento consulta esta publicación.
        $checkpoint->put(self::CHECKPOINT_PUBLISH, $publishId);

        $this->upload($uploadUrl, $video, $chunkSize, $chunks);

        return $this->waitForPublication($tokens, $publishId, $checkpoint, (string) ($creator['creator_username'] ?? ''));
    }

    public function fetchAccountMetrics(OAuthTokens $tokens, string $destinationExternalId, array $credentials): AccountMetrics
    {
        $user = $this->user($tokens, ['follower_count', 'likes_count', 'video_count']);

        return new AccountMetrics(
            followers: (int) ($user['follower_count'] ?? 0),
            engagement: (int) ($user['likes_count'] ?? 0),
            postsCount: (int) ($user['video_count'] ?? 0),
        );
    }

    /**
     * Sólo los videos públicos tienen id y métricas; los privados (o aún en
     * moderación) quedan con el id de la publicación y devuelven 0.
     */
    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        if (preg_match('/^\d+$/', $remoteId) !== 1) {
            $status = $this->status($tokens, $remoteId);
            $remoteId = (string) (((array) ($status['publicaly_available_post_id'] ?? []))[0] ?? '');
            if ($remoteId === '') {
                return new PostMetrics();
            }
        }

        $data = $this->tiktok($this->send('leer las métricas del video', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::API . 'video/query/?fields=id,view_count,like_count,comment_count,share_count', [
                'filters' => ['video_ids' => [$remoteId]],
            ])), 'leer las métricas del video');
        $video = (array) (((array) ($data['videos'] ?? []))[0] ?? []);

        return new PostMetrics(
            impressions: (int) ($video['view_count'] ?? 0),
            likes: (int) ($video['like_count'] ?? 0),
            comments: (int) ($video['comment_count'] ?? 0),
            shares: (int) ($video['share_count'] ?? 0),
        );
    }

    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        return []; // TikTok no ofrece comentarios a apps comerciales
    }

    public function replyToConversation(OAuthTokens $tokens, string $conversationExternalId, string $body, array $credentials): InboxReplyResult
    {
        throw new SocialProviderException('TikTok no permite responder comentarios desde aplicaciones externas.');
    }

    protected function isTokenError(Response $response): bool
    {
        // access_token_invalid (caducado/revocado) y scope_not_authorized (hay que reautorizar): 401.
        return $response->status() === 401;
    }

    /**
     * @return array{0: int, 1: int}  [tamaño de parte, nº de partes]
     */
    private function chunking(int $size): array
    {
        if ($size <= self::SINGLE_CHUNK_MAX) {
            return [$size, 1];
        }

        return [self::CHUNK_SIZE, intdiv($size, self::CHUNK_SIZE)];
    }

    /**
     * Sube el video por partes en orden (Content-Range); la última absorbe el
     * resto. TikTok responde 206 por parte y 201 al completar.
     */
    private function upload(string $uploadUrl, MediaFile $video, int $chunkSize, int $chunks): void
    {
        $stream = $video->open();
        try {
            for ($i = 0; $i < $chunks; $i++) {
                $first = $i * $chunkSize;
                $last = $i === $chunks - 1 ? $video->sizeBytes - 1 : $first + $chunkSize - 1;
                $bytes = (string) stream_get_contents($stream, $last - $first + 1, $first);

                $response = $this->send('subir el video', fn () => Http::timeout(300)
                    ->withHeaders(['Content-Range' => "bytes {$first}-{$last}/{$video->sizeBytes}"])
                    ->withBody($bytes, $video->mimeType)
                    ->put($uploadUrl));

                if (! in_array($response->status(), [200, 201, 206], true)) {
                    throw new SocialProviderException('TikTok rechazó una parte del video (HTTP ' . $response->status() . ').');
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Consulta el estado hasta que se publica o falla. Si se agota la espera, el
     * reintento del job vuelve a consultarlo (checkpoint) sin subir otra vez.
     */
    private function waitForPublication(OAuthTokens $tokens, string $publishId, PublishCheckpoint $checkpoint, string $username): PublishResult
    {
        for ($i = 0; $i < self::STATUS_CHECKS; $i++) {
            $status = $this->status($tokens, $publishId);
            $state = (string) ($status['status'] ?? '');

            if ($state === 'PUBLISH_COMPLETE') {
                $postId = (string) (((array) ($status['publicaly_available_post_id'] ?? []))[0] ?? '');
                $profile = 'https://www.tiktok.com/' . ($username !== '' ? '@' . $username : '');

                // Privado o aún en moderación: sin id público, se guarda el de la publicación.
                return $postId !== ''
                    ? new PublishResult($postId, $profile . '/video/' . $postId)
                    : new PublishResult($publishId, $profile);
            }
            if ($state === 'FAILED') {
                $checkpoint->forget(self::CHECKPOINT_PUBLISH);
                $reason = (string) ($status['fail_reason'] ?? '');

                throw new SocialProviderException('TikTok no pudo publicar el video: ' . (self::FAIL_REASONS[$reason] ?? ($reason !== '' ? $reason : 'motivo desconocido')) . '.');
            }

            if ($i < self::STATUS_CHECKS - 1) {
                Sleep::for(self::STATUS_INTERVAL_SECONDS)->seconds();
            }
        }

        throw new SocialProviderException('TikTok sigue procesando el video; el reintento consultará el resultado sin volver a subirlo.');
    }

    /**
     * @return array<string, mixed>
     */
    private function status(OAuthTokens $tokens, string $publishId): array
    {
        return $this->tiktok($this->send('consultar la publicación', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::API . 'post/publish/status/fetch/', ['publish_id' => $publishId])), 'consultar la publicación');
    }

    /**
     * creator_info y, si la cuenta no puede publicar ahora, el motivo (TikTok
     * lo devuelve con HTTP 200 y un código de error).
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function creatorInfo(OAuthTokens $tokens): array
    {
        $response = $this->send('consultar la cuenta', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::API . 'post/publish/creator_info/query/'));

        $code = (string) ($response->json('error.code') ?? '');
        $blocked = match ($code) {
            'spam_risk_too_many_posts' => 'la cuenta alcanzó el límite diario de publicaciones; inténtalo más tarde.',
            'spam_risk_user_banned_from_posting' => 'TikTok no permite publicar a esta cuenta ahora mismo.',
            'reached_active_user_cap' => 'la app alcanzó el máximo diario de cuentas que publican; inténtalo más tarde.',
            default => null,
        };
        if ($blocked !== null && $response->successful()) {
            return [(array) ($response->json('data') ?? []), $blocked];
        }

        return [$this->tiktok($response, 'consultar la cuenta'), null];
    }

    private function username(OAuthTokens $tokens): string
    {
        try {
            return (string) ($this->creatorInfo($tokens)[0]['creator_username'] ?? '');
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            return '';
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function user(OAuthTokens $tokens, array $fields): array
    {
        $data = $this->tiktok($this->send('leer la cuenta', fn () => $this->api($this->accessToken($tokens))
            ->get(self::API . 'user/info/', ['fields' => implode(',', $fields)])), 'leer la cuenta');

        return (array) ($data['user'] ?? []);
    }

    /**
     * Respuesta de TikTok: sólo es correcta si `error.code` es «ok» (algunos
     * errores llegan con HTTP 200). Devuelve `data`.
     *
     * @return array<string, mixed>
     */
    private function tiktok(Response $response, string $action): array
    {
        $code = (string) ($response->json('error.code') ?? '');
        if ($response->successful() && ($code === 'ok' || $code === '')) {
            return (array) ($response->json('data') ?? []);
        }

        $message = (string) ($response->json('error.message') ?: $code ?: ('HTTP ' . $response->status()));
        if ($this->isTokenError($response) || $code === 'access_token_invalid') {
            throw new SocialTokenExpiredException(
                $code === 'scope_not_authorized'
                    ? 'TikTok no tiene todos los permisos necesarios: reconecta la cuenta y acéptalos todos.'
                    : 'TikTok rechazó el token de acceso: ' . $message,
            );
        }

        throw new SocialProviderException('TikTok no permitió ' . $action . ': ' . $message);
    }
}
