<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\SocialConnections\Contracts\AccountMetrics;
use App\Modules\SocialConnections\Contracts\DeletesRemotePosts;
use App\Modules\SocialConnections\Contracts\HasApiVersion;
use App\Modules\SocialConnections\Contracts\HasPublishingLimits;
use App\Modules\SocialConnections\Contracts\InboxMessageData;
use App\Modules\SocialConnections\Contracts\InboxReplyResult;
use App\Modules\SocialConnections\Contracts\InboxThread;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PostMetrics;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Contracts\PublishResult;
use App\Modules\SocialConnections\Contracts\RemoteAccount;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\OAuth2\AbstractOAuth2Provider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Threads (API de Meta en graph.threads.net, docs/06). Usa una app propia (el
 * «Threads App ID/secret», distinto del de Facebook). Publica en dos pasos
 * (contenedor → threads_publish), como Instagram: texto (≤ 500), imagen, video
 * y carrusel de 2–20 archivos, descargados desde URLs públicas; métricas de
 * cuenta y de publicación, y respuestas del inbox.
 *
 * Sin PKCE. El código da un token de 1 h que se canjea al momento por uno de
 * 60 días; ese token se renueva consigo mismo (th_refresh_token), así que se
 * guarda también como «refresh token».
 */
class ThreadsProvider extends AbstractOAuth2Provider implements DeletesRemotePosts, HasApiVersion, HasPublishingLimits
{
    private const GRAPH = 'https://graph.threads.net/';

    private const DEFAULT_VERSION = 'v1.0';

    private const CAROUSEL_MAX = 20;

    /** Espera máxima al procesamiento de UNA publicación: 40 consultas × 3 s. */
    public const STATUS_CHECKS = 40;

    private const STATUS_INTERVAL_SECONDS = 3;

    /** Los contenedores caducan a las 24 h: se reutilizan hasta las 23. */
    private const CONTAINER_TTL_SECONDS = 23 * 3600;

    private const CHECKPOINT_CONTAINERS = 'threads.containers';

    private const CHECKPOINT_MEDIA = 'threads.media_id';

    public function key(): string
    {
        return 'threads';
    }

    protected function displayName(): string
    {
        return 'Threads';
    }

    public function usesPkce(): bool
    {
        return false;
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://threads.com/oauth/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return self::GRAPH . 'oauth/access_token';
    }

    protected function scopeSeparator(): string
    {
        return ',';
    }

    public function capabilities(): array
    {
        return [
            Capability::TEXT => true,
            Capability::IMAGE => true,
            Capability::MULTI_IMAGE => true,
            Capability::VIDEO => true,
            Capability::CAROUSEL => true,
            Capability::LINK => true,
            Capability::COMMENTS_READ => true,
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function publishingLimits(): array
    {
        return ['text' => 500, 'media' => self::CAROUSEL_MAX];
    }

    public function defaultScopes(): array
    {
        return [
            'threads_basic',
            'threads_content_publish',
            'threads_read_replies',
            'threads_manage_replies',
            'threads_manage_insights',
        ];
    }

    public function apiVersionSetting(): array
    {
        return [
            'key' => 'api_version',
            'label' => 'Versión de la API de Threads',
            'default' => self::DEFAULT_VERSION,
            'pattern' => '/^v\d+\.\d+$/',
            'example' => self::DEFAULT_VERSION,
            'hint' => 'Por ahora Meta sólo publica la v1.0 de la API de Threads.',
        ];
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier, array $credentials): OAuthTokens
    {
        $this->assertConfigured($credentials);

        $short = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], $credentials, 'completar la autorización');

        // Token de 1 h → token de 60 días (el secreto viaja en la query: sólo servidor).
        $long = $this->graphJson($this->send('obtener un token de larga duración', fn () => Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->get(self::GRAPH . 'access_token', [
                'grant_type' => 'th_exchange_token',
                'client_secret' => $credentials['client_secret'],
                'access_token' => (string) $short['access_token'],
            ])), 'obtener un token de larga duración');

        return $this->longLivedTokens($long);
    }

    public function refreshTokens(string $refreshToken, array $credentials): OAuthTokens
    {
        $data = $this->graphJson($this->send('renovar el acceso', fn () => Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->get(self::GRAPH . 'refresh_access_token', [
                'grant_type' => 'th_refresh_token',
                'access_token' => $refreshToken,
            ])), 'renovar el acceso');

        return $this->longLivedTokens($data);
    }

    /**
     * Un app access token sólo se emite si el ID y el secreto de la app son correctos.
     */
    public function verifyCredentials(array $credentials): void
    {
        $this->assertConfigured($credentials);

        $this->graphJson($this->send('validar las credenciales de la app', fn () => Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->get(self::GRAPH . 'oauth/access_token', [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'grant_type' => 'client_credentials',
            ])), 'validar las credenciales de la app');
    }

    public function fetchAccount(OAuthTokens $tokens, array $credentials): RemoteAccount
    {
        $me = $this->profile($tokens, $credentials);

        return new RemoteAccount((string) ($me['id'] ?? ''), $this->handle($me));
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $me = $this->profile($tokens, $credentials);

        return [new RemoteDestination(
            externalId: (string) ($me['id'] ?? ''),
            name: $this->handle($me),
            type: 'profile',
            capabilities: $this->capabilities(),
            metadata: array_filter([
                'username' => $me['username'] ?? null,
                'profile_picture_url' => $me['threads_profile_picture_url'] ?? null,
            ]),
        )];
    }

    public function publish(OAuthTokens $tokens, string $destinationExternalId, PublishPayload $payload, array $credentials): PublishResult
    {
        $token = $this->accessToken($tokens);
        $checkpoint = $payload->checkpoint;

        // Publicado en un intento anterior que no llegó a registrarlo: no se duplica.
        $publishedId = (string) $checkpoint->get(self::CHECKPOINT_MEDIA, '');
        if ($publishedId !== '') {
            return new PublishResult($publishedId, $this->permalink($credentials, $publishedId, $token));
        }

        $urls = array_slice($payload->mediaUrls, 0, self::CAROUSEL_MAX);
        $checksLeft = self::STATUS_CHECKS;
        $user = $destinationExternalId;

        if (count($urls) <= 1) {
            $params = match (true) {
                $urls === [] => ['media_type' => 'TEXT', 'text' => $payload->body],
                $payload->isVideo(0) => ['media_type' => 'VIDEO', 'video_url' => $urls[0], 'text' => $payload->body],
                default => ['media_type' => 'IMAGE', 'image_url' => $urls[0], 'text' => $payload->body],
            };
            $container = $this->prepare($credentials, $user, $token, $checkpoint, 'main', $params);
        } else {
            // Primero se crean (o retoman) todos los hijos: Threads los procesa en
            // paralelo y la espera total es la del más lento.
            $children = [];
            foreach ($urls as $i => $url) {
                $children[$i] = $this->prepare($credentials, $user, $token, $checkpoint, "child:{$i}", $payload->isVideo($i)
                    ? ['media_type' => 'VIDEO', 'video_url' => $url, 'is_carousel_item' => 'true']
                    : ['media_type' => 'IMAGE', 'image_url' => $url, 'is_carousel_item' => 'true']);
            }
            foreach ($children as $i => $child) {
                $this->waitUntilReady($credentials, $child, $token, $checksLeft, $checkpoint, "child:{$i}");
            }

            $container = $this->prepare($credentials, $user, $token, $checkpoint, 'main', [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $children),
                'text' => $payload->body,
            ]);
        }
        $this->waitUntilReady($credentials, $container, $token, $checksLeft, $checkpoint, 'main');

        $media = $this->graphJson($this->send('publicar en Threads', fn () => Http::asForm()->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($this->url($credentials, $user . '/threads_publish'), ['creation_id' => $container, 'access_token' => $token])), 'publicar en Threads');
        $mediaId = (string) ($media['id'] ?? '');
        if ($mediaId === '') {
            throw new SocialProviderException('Threads no devolvió el identificador de la publicación.');
        }
        // Antes que nada: si el worker muere ahora, el reintento no lo publica otra vez.
        $checkpoint->put(self::CHECKPOINT_MEDIA, $mediaId);

        return new PublishResult($mediaId, $this->permalink($credentials, $mediaId, $token));
    }

    public function fetchAccountMetrics(OAuthTokens $tokens, string $destinationExternalId, array $credentials): AccountMetrics
    {
        $data = $this->get($credentials, $destinationExternalId . '/threads_insights', [
            'metric' => 'views,likes,replies,reposts,quotes,followers_count',
        ], $this->accessToken($tokens), 'leer las métricas de la cuenta');
        $metrics = $this->insights($data);

        return new AccountMetrics(
            followers: $metrics['followers_count'] ?? 0,
            impressions: $metrics['views'] ?? 0,
            engagement: ($metrics['likes'] ?? 0) + ($metrics['replies'] ?? 0) + ($metrics['reposts'] ?? 0) + ($metrics['quotes'] ?? 0),
        );
    }

    /**
     * Borra la publicación (DELETE /{id}). Exige el permiso threads_delete, que
     * se añade en SUPERADMIN (y en la app de Meta) y obliga a reconectar la
     * cuenta; Meta permite 100 borrados al día por cuenta.
     */
    public function deleteRemotePost(OAuthTokens $tokens, string $remoteId, array $credentials): void
    {
        $action = 'borrar la publicación';
        $token = $this->accessToken($tokens);
        $response = $this->send($action, fn () => Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->delete($this->url($credentials, $remoteId) . '?' . http_build_query(['access_token' => $token])));

        if ($response->successful()) {
            if ($response->json('success') !== true) {
                throw new SocialProviderException('Threads no confirmó el borrado de la publicación.');
            }

            return;
        }

        $code = (int) ($response->json('error.code') ?? $response->json('code') ?? 0);
        if ($code === 10 || $code === 200) {
            throw new SocialProviderException(
                'Threads sólo permite borrar con el permiso threads_delete: añádelo en la configuración de Threads y reconecta la cuenta.',
            );
        }
        // Como en Facebook, «no existe» y «sin permiso» pueden llegar igual.
        if (! $this->isTokenError($response) && ! $this->postExists($credentials, $remoteId, $token)) {
            return;
        }

        $this->graphJson($response, $action);
    }

    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        $data = $this->get($credentials, $remoteId . '/insights', [
            'metric' => 'views,likes,replies,reposts,quotes,shares',
        ], $this->accessToken($tokens), 'leer las métricas de la publicación');
        $metrics = $this->insights($data);

        return new PostMetrics(
            impressions: $metrics['views'] ?? 0,
            likes: $metrics['likes'] ?? 0,
            comments: $metrics['replies'] ?? 0,
            shares: ($metrics['reposts'] ?? 0) + ($metrics['quotes'] ?? 0) + ($metrics['shares'] ?? 0),
        );
    }

    /**
     * Respuestas de primer nivel a las publicaciones recientes de la cuenta.
     */
    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $token = $this->accessToken($tokens);
        $posts = $this->get($credentials, $destinationExternalId . '/threads', [
            'fields' => 'id,timestamp',
            'limit' => 10,
        ], $token, 'leer tus publicaciones');

        $threads = [];
        foreach ((array) ($posts['data'] ?? []) as $post) {
            $postId = (string) ($post['id'] ?? '');
            if ($postId === '') {
                continue;
            }

            $replies = $this->get($credentials, $postId . '/replies', [
                'fields' => 'id,text,username,timestamp,is_reply_owned_by_me',
                'reverse' => 'true',
            ], $token, 'leer las respuestas');

            foreach ((array) ($replies['data'] ?? []) as $reply) {
                if (! isset($reply['id']) || ($reply['is_reply_owned_by_me'] ?? false) === true) {
                    continue; // respuesta de la propia cuenta
                }
                $author = '@' . ltrim((string) ($reply['username'] ?? 'usuario'), '@');
                $sentAt = isset($reply['timestamp']) ? Carbon::parse((string) $reply['timestamp']) : Carbon::now();

                $threads[] = new InboxThread(
                    externalId: (string) $reply['id'],
                    type: 'comment',
                    participantName: $author,
                    participantExternalId: (string) ($reply['username'] ?? ''),
                    lastMessageAt: $sentAt,
                    messages: [new InboxMessageData(
                        externalId: (string) $reply['id'],
                        authorName: $author,
                        authorExternalId: (string) ($reply['username'] ?? ''),
                        body: (string) ($reply['text'] ?? ''),
                        direction: 'inbound',
                        sentAt: $sentAt,
                    )],
                );
            }
        }

        return $threads;
    }

    public function replyToConversation(OAuthTokens $tokens, string $conversationExternalId, string $body, array $credentials): InboxReplyResult
    {
        $token = $this->accessToken($tokens);
        $container = $this->createContainer($credentials, 'me', $token, [
            'media_type' => 'TEXT',
            'text' => $body,
            'reply_to_id' => $conversationExternalId,
        ]);

        $checks = self::STATUS_CHECKS;
        $this->waitUntilReady($credentials, $container, $token, $checks, new PublishCheckpoint(), 'reply');
        $reply = $this->graphJson($this->send('responder', fn () => Http::asForm()->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($this->url($credentials, 'me/threads_publish'), ['creation_id' => $container, 'access_token' => $token])), 'responder');

        return new InboxReplyResult((string) ($reply['id'] ?? ''));
    }

    /**
     * Contenedor de un hueco de la publicación ('main' o 'child:N'): en un
     * reintento reutiliza el del intento anterior si sigue válido.
     *
     * @param  array<string, string>  $credentials
     * @param  array<string, string>  $params
     */
    private function prepare(array $credentials, string $user, string $token, PublishCheckpoint $checkpoint, string $slot, array $params): string
    {
        // Las URLs de los archivos son firmadas y cambian en cada intento: no cuentan.
        $signature = sha1((string) json_encode(array_diff_key($params, ['image_url' => true, 'video_url' => true])));
        $containers = (array) $checkpoint->get(self::CHECKPOINT_CONTAINERS, []);
        $saved = is_array($containers[$slot] ?? null) ? $containers[$slot] : null;

        if ($saved !== null
            && ($saved['signature'] ?? null) === $signature
            && Carbon::now()->getTimestamp() - (int) ($saved['created_at'] ?? 0) < self::CONTAINER_TTL_SECONDS
        ) {
            $id = (string) ($saved['id'] ?? '');
            $status = $id !== '' ? (string) ($this->status($credentials, $id, $token)['status'] ?? '') : '';
            if ($status === 'PUBLISHED') {
                throw new SocialProviderException('Threads indica que este contenido ya se publicó en un intento anterior: compruébalo en el perfil.');
            }
            if ($status === 'FINISHED' || $status === 'IN_PROGRESS') {
                return $id;
            }
        }

        $id = $this->createContainer($credentials, $user, $token, $params);
        $containers[$slot] = ['id' => $id, 'signature' => $signature, 'created_at' => Carbon::now()->getTimestamp()];
        $checkpoint->put(self::CHECKPOINT_CONTAINERS, $containers);

        return $id;
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, string>  $params
     */
    private function createContainer(array $credentials, string $user, string $token, array $params): string
    {
        $container = $this->graphJson($this->send('preparar la publicación', fn () => Http::asForm()->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($this->url($credentials, $user . '/threads'), [...$params, 'access_token' => $token])), 'preparar la publicación');

        $id = (string) ($container['id'] ?? '');
        if ($id === '') {
            throw new SocialProviderException('Threads no devolvió el contenedor de la publicación.');
        }

        return $id;
    }

    /**
     * Espera a que el contenedor esté listo (FINISHED), consumiendo el
     * presupuesto de consultas de la publicación. Si se agota, el reintento
     * retoma el mismo contenedor; si Threads lo da por fallido, se olvida.
     *
     * @param  array<string, string>  $credentials
     */
    private function waitUntilReady(array $credentials, string $container, string $token, int &$checksLeft, PublishCheckpoint $checkpoint, string $slot): void
    {
        while ($checksLeft > 0) {
            $checksLeft--;
            $status = $this->status($credentials, $container, $token);
            $code = (string) ($status['status'] ?? '');

            if ($code === 'FINISHED' || $code === 'PUBLISHED') {
                return;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                $containers = (array) $checkpoint->get(self::CHECKPOINT_CONTAINERS, []);
                unset($containers[$slot]);
                $checkpoint->put(self::CHECKPOINT_CONTAINERS, $containers);

                throw new SocialProviderException('Threads no pudo procesar el archivo: ' . ($status['error_message'] ?? $code) . '.');
            }

            if ($checksLeft > 0) {
                Sleep::for(self::STATUS_INTERVAL_SECONDS)->seconds();
            }
        }

        throw new SocialProviderException('Threads sigue procesando el archivo; el reintento retomará el mismo contenido.');
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array<string, mixed>
     */
    private function status(array $credentials, string $container, string $token): array
    {
        return $this->get($credentials, $container, ['fields' => 'id,status,error_message'], $token, 'consultar el procesamiento');
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function permalink(array $credentials, string $mediaId, string $token): string
    {
        try {
            $media = $this->get($credentials, $mediaId, ['fields' => 'permalink'], $token, 'leer el enlace');

            return (string) ($media['permalink'] ?? 'https://www.threads.com/');
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            return 'https://www.threads.com/';
        }
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array<string, mixed>
     */
    private function profile(OAuthTokens $tokens, array $credentials): array
    {
        return $this->get($credentials, 'me', [
            'fields' => 'id,username,name,threads_profile_picture_url',
        ], $this->accessToken($tokens), 'leer tu perfil');
    }

    /**
     * @param  array<string, mixed>  $me
     */
    private function handle(array $me): string
    {
        $username = (string) ($me['username'] ?? '');

        return $username !== '' ? '@' . ltrim($username, '@') : (string) ($me['name'] ?? 'Cuenta de Threads');
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(array $credentials, string $path, array $query, string $token, string $action): array
    {
        return $this->graphJson($this->send($action, fn () => Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->get($this->url($credentials, $path), [...$query, 'access_token' => $token])), $action);
    }

    /**
     * ¿Sigue existiendo la publicación? «No existe» llega como 404 o código 100
     * (subcódigo 33); ante cualquier otra respuesta se asume que existe.
     *
     * @param  array<string, string>  $credentials
     */
    private function postExists(array $credentials, string $id, string $token): bool
    {
        $action = 'comprobar la publicación';
        $response = $this->send($action, fn () => Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->get($this->url($credentials, $id), ['fields' => 'id', 'access_token' => $token]));
        if ($response->successful()) {
            return true;
        }
        if ($this->isTokenError($response)) {
            $this->graphJson($response, $action);
        }

        $code = (int) ($response->json('error.code') ?? $response->json('code') ?? 0);

        return ! ($response->status() === 404 || ($code === 100 && (int) ($response->json('error.error_subcode') ?? 0) === 33));
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function url(array $credentials, string $path): string
    {
        return self::GRAPH . $this->setting($credentials, 'api_version', '/^v\d+\.\d+$/', self::DEFAULT_VERSION) . '/' . ltrim($path, '/');
    }

    /**
     * Métricas de Insights: totales (`total_value.value`) o series (se suman).
     *
     * @param  array<string, mixed>  $response
     * @return array<string, int>
     */
    private function insights(array $response): array
    {
        $map = [];
        foreach ((array) ($response['data'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['name'])) {
                continue;
            }
            if (isset($item['total_value']['value']) && is_numeric($item['total_value']['value'])) {
                $map[(string) $item['name']] = (int) $item['total_value']['value'];

                continue;
            }
            $map[(string) $item['name']] = (int) array_sum(array_map(
                fn ($point) => is_array($point) && is_numeric($point['value'] ?? null) ? (int) $point['value'] : 0,
                (array) ($item['values'] ?? []),
            ));
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function longLivedTokens(array $data): OAuthTokens
    {
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new SocialProviderException('Threads no devolvió un token de acceso.');
        }

        return new OAuthTokens(
            accessToken: $token,
            // Threads renueva el token de larga duración con él mismo.
            refreshToken: $token,
            expiresAt: isset($data['expires_in']) && is_numeric($data['expires_in'])
                ? Carbon::now()->addSeconds((int) $data['expires_in'])
                : Carbon::now()->addDays(60),
            scopes: $this->defaultScopes(),
        );
    }

    /**
     * Respuesta de Graph: el error llega en `error` ({message, code}) o plano
     * ({error_type, code, error_message}); 190 = token caducado o revocado.
     *
     * @return array<string, mixed>
     */
    private function graphJson(Response $response, string $action): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $code = (int) ($response->json('error.code') ?? $response->json('code') ?? 0);
        $message = $this->errorMessage($response);
        if ($code === 190 || $code === 102 || $response->status() === 401) {
            throw new SocialTokenExpiredException('Threads rechazó el token de acceso: ' . $message);
        }

        throw new SocialProviderException('Threads no permitió ' . $action . ': ' . $message);
    }

    protected function isTokenError(Response $response): bool
    {
        $code = (int) ($response->json('error.code') ?? 0);

        return $code === 190 || $code === 102 || $response->status() === 401;
    }
}
