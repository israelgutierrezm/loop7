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
use App\Modules\SocialConnections\Contracts\MediaFile;
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
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * LinkedIn (API REST versionada, docs/06). Con los productos de alta directa
 * («Sign In with LinkedIn using OpenID Connect» y «Share on LinkedIn») publica
 * en el perfil del miembro: texto, una o varias imágenes (2–20) y video. Las
 * páginas de empresa, sus métricas y comentarios exigen la Community
 * Management API (revisión de LinkedIn): se activan añadiendo sus scopes en
 * SUPERADMIN y el adaptador los usa sólo si el token los incluye.
 *
 * Sin PKCE (LinkedIn sólo lo documenta para apps nativas) y, salvo socios
 * aprobados, sin refresh token: el acceso dura 60 días y luego hay que
 * reconectar.
 */
class LinkedInProvider extends AbstractOAuth2Provider implements DeletesRemotePosts, HasApiVersion, HasPublishingLimits
{
    private const API = 'https://api.linkedin.com/rest/';

    private const DEFAULT_VERSION = '202609';

    /** Espera a que LinkedIn procese un video subido: hasta 20 × 3 s. */
    private const VIDEO_WAIT_ATTEMPTS = 20;

    private const VIDEO_WAIT_SECONDS = 3;

    private const MAX_PAGES = 25;

    private const ORG_SCOPE = 'rw_organization_admin';

    private const CHECKPOINT_MEDIA = 'linkedin.media';

    private const CHECKPOINT_POST = 'linkedin.post';

    public function key(): string
    {
        return 'linkedin';
    }

    protected function displayName(): string
    {
        return 'LinkedIn';
    }

    public function usesPkce(): bool
    {
        return false;
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }

    public function capabilities(): array
    {
        return [
            Capability::TEXT => true,
            Capability::IMAGE => true,
            Capability::MULTI_IMAGE => true,
            Capability::VIDEO => true,
            Capability::LINK => true,
            // Páginas de empresa con la Community Management API aprobada.
            Capability::COMMENTS_READ => true,
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => true,
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function publishingLimits(): array
    {
        return ['text' => 3000, 'images' => 20, 'videos' => 1];
    }

    public function defaultScopes(): array
    {
        // Productos de alta directa. Para páginas de empresa, añadir en SUPERADMIN:
        // w_organization_social r_organization_social rw_organization_admin
        // r_organization_social_feed w_organization_social_feed.
        return ['openid', 'profile', 'email', 'w_member_social'];
    }

    public function apiVersionSetting(): array
    {
        return [
            'key' => 'api_version',
            'label' => 'Versión de la API (LinkedIn-Version)',
            'default' => self::DEFAULT_VERSION,
            'pattern' => '/^\d{6}$/',
            'example' => self::DEFAULT_VERSION,
            'hint' => 'LinkedIn publica una versión al mes y mantiene cada una al menos un año.',
        ];
    }

    public function deleteRemotePost(OAuthTokens $tokens, string $remoteId, array $credentials): void
    {
        // El URN va codificado en la ruta; borrar es idempotente (204 aunque ya no exista).
        $action = 'borrar la publicación';
        $this->assertDeleted($this->send($action, fn () => $this->rest($tokens, $credentials)
            ->withHeaders(['X-RestLi-Method' => 'DELETE'])
            ->delete(self::API . 'posts/' . rawurlencode($remoteId))), $action);
    }

    public function fetchAccount(OAuthTokens $tokens, array $credentials): RemoteAccount
    {
        $me = $this->userinfo($tokens);

        return new RemoteAccount((string) ($me['sub'] ?? ''), (string) ($me['name'] ?? 'Cuenta de LinkedIn'));
    }

    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $me = $this->userinfo($tokens);
        $destinations = [new RemoteDestination(
            externalId: 'urn:li:person:' . ($me['sub'] ?? ''),
            name: (string) ($me['name'] ?? 'Perfil de LinkedIn'),
            type: 'profile',
            capabilities: [...$this->capabilities(), Capability::COMMENTS_READ => false, Capability::COMMENTS_REPLY => false],
            metadata: array_filter(['picture' => $me['picture'] ?? null]),
        )];

        if (! in_array(self::ORG_SCOPE, $tokens->scopes, true)) {
            return $destinations;
        }

        // Páginas que administra (sólo con la Community Management API).
        try {
            $acls = $this->json($this->send('listar tus páginas', fn () => $this->rest($tokens, $credentials)->get(self::API . 'organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'state' => 'APPROVED',
                'count' => 100,
            ])), 'listar tus páginas');
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            return $destinations;
        }

        $organizations = [];
        foreach ((array) ($acls['elements'] ?? []) as $acl) {
            $urn = (string) ($acl['organization'] ?? $acl['organizationTarget'] ?? '');
            if (str_starts_with($urn, 'urn:li:organization:')) {
                $organizations[$urn] = true;
            }
        }

        foreach (array_slice(array_keys($organizations), 0, self::MAX_PAGES) as $urn) {
            $id = substr($urn, strlen('urn:li:organization:'));
            try {
                $org = $this->json($this->send('leer la página', fn () => $this->rest($tokens, $credentials)->get(self::API . 'organizations/' . $id)), 'leer la página');
            } catch (SocialTokenExpiredException $e) {
                throw $e;
            } catch (SocialProviderException) {
                $org = [];
            }

            $destinations[] = new RemoteDestination(
                externalId: $urn,
                name: (string) ($org['localizedName'] ?? ('Página ' . $id)),
                type: 'page',
                capabilities: $this->capabilities(),
                metadata: array_filter(['vanity_name' => $org['vanityName'] ?? null]),
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
        $checkpoint = $payload->checkpoint;
        // Crear una publicación no es idempotente en LinkedIn: si ya se creó en
        // un intento anterior, se devuelve esa.
        $existing = $checkpoint->get(self::CHECKPOINT_POST);
        if (is_string($existing) && $existing !== '') {
            return new PublishResult($existing, $this->postUrl($existing));
        }

        $post = [
            'author' => $destinationExternalId,
            'commentary' => $this->littleText($payload->body),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $files = $payload->mediaFiles;
        if ($files !== []) {
            $videos = array_values(array_filter($files, fn (MediaFile $f) => $f->isVideo()));
            if ($videos !== [] && count($files) > 1) {
                throw new SocialProviderException('LinkedIn no permite mezclar un video con otros archivos en la misma publicación.');
            }

            $urns = $this->uploadMedia($tokens, $credentials, $destinationExternalId, $files, $checkpoint);
            $post['content'] = match (true) {
                $videos !== [] => ['media' => ['id' => $urns[0], 'title' => Str::limit($payload->title !== '' ? $payload->title : $this->firstLine($payload->body), 200)]],
                count($urns) === 1 => ['media' => ['id' => $urns[0], 'altText' => '']],
                default => ['multiImage' => ['images' => array_map(fn (string $urn) => ['id' => $urn, 'altText' => ''], $urns)]],
            };
        }

        $response = $this->send('publicar', fn () => $this->rest($tokens, $credentials)->post(self::API . 'posts', $post));
        $this->json($response, 'publicar');

        $urn = $response->header('x-restli-id');
        if ($urn === '') {
            throw new SocialProviderException('LinkedIn aceptó la publicación pero no devolvió su identificador.');
        }
        $checkpoint->put(self::CHECKPOINT_POST, $urn);

        return new PublishResult($urn, $this->postUrl($urn));
    }

    public function fetchAccountMetrics(OAuthTokens $tokens, string $destinationExternalId, array $credentials): AccountMetrics
    {
        if (str_starts_with($destinationExternalId, 'urn:li:organization:')) {
            $size = $this->optional(fn () => $this->json($this->send('leer los seguidores', fn () => $this->rest($tokens, $credentials)
                ->get(self::API . 'networkSizes/' . rawurlencode($destinationExternalId), ['edgeType' => 'COMPANY_FOLLOWED_BY_MEMBER'])), 'leer los seguidores'));
            $stats = $this->optional(fn () => $this->json($this->send('leer las estadísticas', fn () => $this->rest($tokens, $credentials)
                ->get(self::API . 'organizationalEntityShareStatistics', [
                    'q' => 'organizationalEntity',
                    'organizationalEntity' => $destinationExternalId,
                ])), 'leer las estadísticas'));
            $totals = (array) ($stats['elements'][0]['totalShareStatistics'] ?? []);

            return new AccountMetrics(
                followers: (int) ($size['firstDegreeSize'] ?? 0),
                reach: (int) ($totals['uniqueImpressionsCount'] ?? 0),
                impressions: (int) ($totals['impressionCount'] ?? 0),
                engagement: (int) ($totals['likeCount'] ?? 0) + (int) ($totals['commentCount'] ?? 0) + (int) ($totals['shareCount'] ?? 0) + (int) ($totals['clickCount'] ?? 0),
            );
        }

        // Perfil: seguidores con r_member_profileAnalytics (Community Management API).
        $followers = $this->optional(fn () => $this->json($this->send('leer los seguidores', fn () => $this->rest($tokens, $credentials)
            ->get(self::API . 'memberFollowersCount', ['q' => 'me'])), 'leer los seguidores'));

        return new AccountMetrics(followers: (int) ($followers['elements'][0]['memberFollowersCount'] ?? 0));
    }

    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        // Publicaciones de páginas: estadísticas de la organización autora.
        if (in_array(self::ORG_SCOPE, $tokens->scopes, true)) {
            $post = $this->optional(fn () => $this->json($this->send('leer la publicación', fn () => $this->rest($tokens, $credentials)
                ->get(self::API . 'posts/' . rawurlencode($remoteId), ['viewContext' => 'AUTHOR'])), 'leer la publicación'));
            $author = (string) ($post['author'] ?? '');
            if (str_starts_with($author, 'urn:li:organization:')) {
                $kind = str_starts_with($remoteId, 'urn:li:ugcPost:') ? 'ugcPosts' : 'shares';
                $stats = $this->optional(fn () => $this->json($this->send('leer las estadísticas', fn () => $this->rest($tokens, $credentials)
                    ->get(self::API . 'organizationalEntityShareStatistics?' . http_build_query([
                        'q' => 'organizationalEntity',
                        'organizationalEntity' => $author,
                    ]) . '&' . $kind . '=List(' . rawurlencode($remoteId) . ')')), 'leer las estadísticas'));
                $totals = (array) ($stats['elements'][0]['totalShareStatistics'] ?? []);

                return new PostMetrics(
                    impressions: (int) ($totals['impressionCount'] ?? 0),
                    reach: (int) ($totals['uniqueImpressionsCount'] ?? 0),
                    likes: (int) ($totals['likeCount'] ?? 0),
                    comments: (int) ($totals['commentCount'] ?? 0),
                    shares: (int) ($totals['shareCount'] ?? 0),
                    clicks: (int) ($totals['clickCount'] ?? 0),
                );
            }
        }

        // Publicaciones del perfil: una métrica por llamada (r_member_postAnalytics).
        $entity = str_starts_with($remoteId, 'urn:li:ugcPost:') ? '(ugc:' . rawurlencode($remoteId) . ')' : '(share:' . rawurlencode($remoteId) . ')';
        $metric = function (string $type) use ($tokens, $credentials, $entity): int {
            $data = $this->optional(fn () => $this->json($this->send('leer las métricas', fn () => $this->rest($tokens, $credentials)
                ->get(self::API . 'memberCreatorPostAnalytics?q=entity&entity=' . $entity . '&queryType=' . $type . '&aggregation=TOTAL')), 'leer las métricas'));

            return (int) ($data['elements'][0]['count'] ?? 0);
        };

        $impressions = $metric('IMPRESSION');
        if ($impressions === 0 && ! in_array('r_member_postAnalytics', $tokens->scopes, true)) {
            return new PostMetrics(); // sin permiso de analítica del miembro
        }

        return new PostMetrics(
            impressions: $impressions,
            reach: $metric('MEMBERS_REACHED'),
            likes: $metric('REACTION'),
            comments: $metric('COMMENT'),
            shares: $metric('RESHARE'),
        );
    }

    /**
     * Comentarios de las publicaciones recientes de una página (Community
     * Management API). En perfiles LinkedIn no permite leerlos.
     */
    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        if (! str_starts_with($destinationExternalId, 'urn:li:organization:')
            || ! in_array('r_organization_social_feed', $tokens->scopes, true)
        ) {
            return [];
        }

        $posts = $this->json($this->send('leer las publicaciones', fn () => $this->rest($tokens, $credentials)->get(self::API . 'posts', [
            'author' => $destinationExternalId,
            'q' => 'author',
            'count' => 10,
            'sortBy' => 'LAST_MODIFIED',
        ])), 'leer las publicaciones');

        $threads = [];
        foreach ((array) ($posts['elements'] ?? []) as $post) {
            $postUrn = (string) ($post['id'] ?? '');
            if ($postUrn === '') {
                continue;
            }

            $comments = $this->optional(fn () => $this->json($this->send('leer los comentarios', fn () => $this->rest($tokens, $credentials)
                ->get(self::API . 'socialActions/' . rawurlencode($postUrn) . '/comments', ['count' => 25])), 'leer los comentarios'));

            foreach ((array) ($comments['elements'] ?? []) as $comment) {
                $actor = (string) ($comment['actor'] ?? '');
                $commentUrn = (string) ($comment['commentUrn'] ?? '');
                if ($commentUrn === '' || $actor === $destinationExternalId) {
                    continue; // respuesta de la propia página
                }
                $sentAt = isset($comment['created']['time']) ? Carbon::createFromTimestampMs((int) $comment['created']['time']) : Carbon::now();
                $author = str_starts_with($actor, 'urn:li:organization:') ? 'Página de LinkedIn' : 'Miembro de LinkedIn';

                // El id lleva la página: responder exige actuar en su nombre.
                $threadId = $destinationExternalId . '|' . $commentUrn;
                $threads[] = new InboxThread(
                    externalId: $threadId,
                    type: 'comment',
                    participantName: $author,
                    participantExternalId: $actor,
                    lastMessageAt: $sentAt,
                    messages: [new InboxMessageData(
                        externalId: $commentUrn,
                        authorName: $author,
                        authorExternalId: $actor,
                        body: (string) ($comment['message']['text'] ?? ''),
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
        [$actor, $commentUrn] = array_pad(explode('|', $conversationExternalId, 2), 2, '');
        // urn:li:comment:(urn:li:share:123,456) → objeto = urn:li:share:123
        if ($commentUrn === '' || preg_match('/^urn:li:comment:\((urn:li:[a-zA-Z]+:[^,]+),/', $commentUrn, $m) !== 1) {
            throw new SocialProviderException('No se reconoce el comentario de LinkedIn al que responder.');
        }

        $response = $this->send('responder el comentario', fn () => $this->rest($tokens, $credentials)
            ->post(self::API . 'socialActions/' . rawurlencode($commentUrn) . '/comments', [
                'actor' => $actor,
                'object' => $m[1],
                'message' => ['text' => $body],
                'parentComment' => $commentUrn,
            ]));
        $data = $this->json($response, 'responder el comentario');

        return new InboxReplyResult((string) ($data['commentUrn'] ?? $response->header('x-restli-id')));
    }

    protected function isTokenError(Response $response): bool
    {
        // 401 = token inválido, caducado o revocado por el miembro (serviceErrorCode 65600…).
        return $response->status() === 401;
    }

    /**
     * Sube imágenes o un video y devuelve sus URN, reutilizando los ya subidos
     * en un intento anterior (no se vuelven a subir si el job se reintenta).
     *
     * @param  list<MediaFile>  $files
     * @param  array<string, string>  $credentials
     * @return list<string>
     */
    private function uploadMedia(OAuthTokens $tokens, array $credentials, string $owner, array $files, PublishCheckpoint $checkpoint): array
    {
        $done = (array) ($checkpoint->get(self::CHECKPOINT_MEDIA) ?? []);
        $urns = [];

        foreach ($files as $i => $file) {
            if (isset($done[$i]) && is_string($done[$i])) {
                $urns[] = $done[$i];

                continue;
            }

            $urns[] = $done[$i] = $file->isVideo()
                ? $this->uploadVideo($tokens, $credentials, $owner, $file)
                : $this->uploadImage($tokens, $credentials, $owner, $file);
            $checkpoint->put(self::CHECKPOINT_MEDIA, $done);
        }

        return $urns;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function uploadImage(OAuthTokens $tokens, array $credentials, string $owner, MediaFile $file): string
    {
        $init = $this->json($this->send('preparar la imagen', fn () => $this->rest($tokens, $credentials)
            ->post(self::API . 'images?action=initializeUpload', ['initializeUploadRequest' => ['owner' => $owner]])), 'preparar la imagen');
        $uploadUrl = (string) ($init['value']['uploadUrl'] ?? '');
        $urn = (string) ($init['value']['image'] ?? '');
        if ($uploadUrl === '' || $urn === '') {
            throw new SocialProviderException('LinkedIn no devolvió dónde subir la imagen.');
        }

        // La subida de imágenes va con el token (la de video, no). El stream lo
        // cierra el cliente HTTP al terminar.
        $this->json($this->send('subir la imagen', fn () => Http::withToken($this->accessToken($tokens))
            ->timeout(120)
            ->withBody(Utils::streamFor($file->open()), $file->mimeType)
            ->put($uploadUrl)), 'subir la imagen');

        return $urn;
    }

    /**
     * Video en partes: initializeUpload → PUT de cada parte (sin token) →
     * finalizeUpload con los ETag, y espera a que LinkedIn lo procese.
     *
     * @param  array<string, string>  $credentials
     */
    private function uploadVideo(OAuthTokens $tokens, array $credentials, string $owner, MediaFile $file): string
    {
        $init = $this->json($this->send('preparar el video', fn () => $this->rest($tokens, $credentials)
            ->post(self::API . 'videos?action=initializeUpload', ['initializeUploadRequest' => [
                'owner' => $owner,
                'fileSizeBytes' => $file->sizeBytes,
                'uploadCaptions' => false,
                'uploadThumbnail' => false,
            ]])), 'preparar el video');
        $value = (array) ($init['value'] ?? []);
        $urn = (string) ($value['video'] ?? '');
        $instructions = (array) ($value['uploadInstructions'] ?? []);
        if ($urn === '' || $instructions === []) {
            throw new SocialProviderException('LinkedIn no devolvió dónde subir el video.');
        }

        $stream = $file->open();
        $etags = [];
        try {
            foreach ($instructions as $part) {
                $first = (int) ($part['firstByte'] ?? 0);
                $last = (int) ($part['lastByte'] ?? 0);
                fseek($stream, $first);
                $chunk = (string) stream_get_contents($stream, $last - $first + 1);

                $response = $this->send('subir el video', fn () => Http::timeout(300)
                    ->withBody($chunk, 'application/octet-stream')
                    ->put((string) ($part['uploadUrl'] ?? '')));
                $this->json($response, 'subir el video');
                $etags[] = trim($response->header('ETag'), '"');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->json($this->send('terminar la subida del video', fn () => $this->rest($tokens, $credentials)
            ->post(self::API . 'videos?action=finalizeUpload', ['finalizeUploadRequest' => [
                'video' => $urn,
                'uploadToken' => (string) ($value['uploadToken'] ?? ''),
                'uploadedPartIds' => $etags,
            ]])), 'terminar la subida del video');

        $this->waitForVideo($tokens, $credentials, $urn);

        return $urn;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function waitForVideo(OAuthTokens $tokens, array $credentials, string $urn): void
    {
        for ($i = 0; $i < self::VIDEO_WAIT_ATTEMPTS; $i++) {
            $video = $this->optional(fn () => $this->json($this->send('consultar el video', fn () => $this->rest($tokens, $credentials)
                ->get(self::API . 'videos/' . rawurlencode($urn))), 'consultar el video'));
            $status = (string) ($video['status'] ?? '');

            if ($status === 'AVAILABLE' || $video === []) {
                return; // listo (o sin permiso para consultarlo: se publica igualmente)
            }
            if ($status === 'PROCESSING_FAILED') {
                throw new SocialProviderException('LinkedIn no pudo procesar el video: ' . ($video['processingFailureReason'] ?? 'formato no admitido') . '.');
            }

            Sleep::for(self::VIDEO_WAIT_SECONDS)->seconds();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function userinfo(OAuthTokens $tokens): array
    {
        // /v2/userinfo (OpenID Connect) no lleva versión.
        return $this->json($this->send('leer tu perfil', fn () => $this->api($this->accessToken($tokens))
            ->get('https://api.linkedin.com/v2/userinfo')), 'leer tu perfil');
    }

    /**
     * Petición a la API REST versionada.
     *
     * @param  array<string, string>  $credentials
     */
    private function rest(OAuthTokens $tokens, array $credentials): PendingRequest
    {
        return $this->api($this->accessToken($tokens))->withHeaders([
            'LinkedIn-Version' => $this->setting($credentials, 'api_version', '/^\d{6}$/', self::DEFAULT_VERSION),
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
    }

    /**
     * Resultado de una lectura que puede no estar permitida (scope o producto
     * sin aprobar): vacía en ese caso; un token rechazado sí se propaga.
     *
     * @param  callable(): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function optional(callable $read): array
    {
        try {
            return $read();
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            return [];
        }
    }

    /**
     * Texto en el formato «little text» de LinkedIn: los caracteres reservados
     * se escapan salvo el # de los hashtags.
     */
    private function littleText(string $text): string
    {
        $escaped = (string) preg_replace('/([|{}@\[\]()<>\\\\*_~])/u', '\\\\$1', $text);

        return (string) preg_replace('/#(?![\p{L}\p{N}_])/u', '\\#', $escaped);
    }

    private function firstLine(string $text): string
    {
        $line = trim((string) strtok(trim($text), "\n"));

        return $line !== '' ? $line : 'Video';
    }

    private function postUrl(string $urn): string
    {
        return 'https://www.linkedin.com/feed/update/' . $urn . '/';
    }
}
