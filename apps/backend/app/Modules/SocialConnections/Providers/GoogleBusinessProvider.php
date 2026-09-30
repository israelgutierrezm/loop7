<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers;

use App\Modules\SocialConnections\Contracts\AccountMetrics;
use App\Modules\SocialConnections\Contracts\DeletesRemotePosts;
use App\Modules\SocialConnections\Contracts\HasPublishingLimits;
use App\Modules\SocialConnections\Contracts\InboxMessageData;
use App\Modules\SocialConnections\Contracts\InboxReplyResult;
use App\Modules\SocialConnections\Contracts\InboxThread;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PostMetrics;
use App\Modules\SocialConnections\Contracts\ProvidesPublishOptions;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Contracts\PublishResult;
use App\Modules\SocialConnections\Contracts\RemoteAccount;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Contracts\RevokesAccess;
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Providers\OAuth2\AbstractOAuth2Provider;
use Illuminate\Support\Carbon;

/**
 * Google Business Profile (docs/06): publica novedades («local posts», API v4)
 * en las fichas de la cuenta, con una foto opcional y un botón de llamada a la
 * acción; trae las reseñas al inbox y permite responderlas, y lee las métricas
 * de la ficha (Performance API v1). Google retiró las métricas por publicación
 * en 2023.
 *
 * El proyecto de Google Cloud necesita que Google apruebe su acceso a las APIs
 * de Business Profile (sin él la cuota es 0). Usa un cliente OAuth propio (puede
 * ser del mismo proyecto que YouTube): con el mismo cliente, revocar el acceso de
 * una red al desconectarla revocaría el de la otra para esa cuenta de Google.
 */
class GoogleBusinessProvider extends AbstractOAuth2Provider implements DeletesRemotePosts, HasPublishingLimits, ProvidesPublishOptions, RevokesAccess
{
    private const ACCOUNTS = 'https://mybusinessaccountmanagement.googleapis.com/v1/';

    private const INFORMATION = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    private const V4 = 'https://mybusiness.googleapis.com/v4/';

    private const PERFORMANCE = 'https://businessprofileperformance.googleapis.com/v1/';

    /** Botones de llamada a la acción; CALL usa el teléfono de la ficha (sin enlace). */
    public const CTA_TYPES = ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

    private const SUMMARY_MAX = 1500;

    private const REPLY_MAX_BYTES = 4096;

    /** Fichas como máximo por conexión (una marca suele tener pocas). */
    private const MAX_LOCATIONS = 100;

    private const IMPRESSIONS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
    ];

    private const ACTIONS = ['CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'BUSINESS_CONVERSATIONS', 'BUSINESS_BOOKINGS'];

    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /** Publicación creada en un intento anterior (crear no es idempotente en Google). */
    private const CHECKPOINT_POST = 'google_business.post';

    public function key(): string
    {
        return 'google_business';
    }

    protected function displayName(): string
    {
        return 'Google Business Profile';
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
            Capability::TEXT => true,
            Capability::IMAGE => true,
            Capability::VIDEO => false,
            Capability::LINK => true,
            Capability::COMMENTS_READ => true,  // reseñas
            Capability::COMMENTS_REPLY => true,
            Capability::ANALYTICS_POST => false, // Google las retiró en 2023
            Capability::ANALYTICS_ACCOUNT => true,
        ];
    }

    public function publishingLimits(): array
    {
        return ['text' => self::SUMMARY_MAX, 'media' => 1];
    }

    public function defaultScopes(): array
    {
        return ['https://www.googleapis.com/auth/business.manage'];
    }

    public function revokeAccess(OAuthTokens $tokens, array $credentials): void
    {
        $this->revokeAt('https://oauth2.googleapis.com/revoke', $tokens, $credentials, authenticateClient: false);
    }

    public function fetchAccount(OAuthTokens $tokens, array $credentials): RemoteAccount
    {
        $accounts = $this->accounts($tokens);
        if ($accounts === []) {
            throw new SocialProviderException('Esta cuenta de Google no administra ningún perfil de empresa.');
        }

        return new RemoteAccount((string) $accounts[0]['name'], (string) ($accounts[0]['accountName'] ?? 'Perfil de empresa'));
    }

    /**
     * Cada ficha de cada cuenta es un destino, con id `accounts/{a}/locations/{l}`
     * (la forma que usan las publicaciones y las reseñas de la API v4).
     */
    public function fetchDestinations(OAuthTokens $tokens, array $credentials): array
    {
        $destinations = [];
        foreach ($this->accounts($tokens) as $account) {
            $pageToken = null;
            do {
                $data = $this->json($this->send('listar tus fichas', fn () => $this->api($this->accessToken($tokens))
                    ->get(self::INFORMATION . $account['name'] . '/locations', array_filter([
                        'readMask' => 'name,title,storefrontAddress,metadata',
                        'pageSize' => 100,
                        'pageToken' => $pageToken,
                    ]))), 'listar tus fichas');

                foreach ((array) ($data['locations'] ?? []) as $location) {
                    if (! is_array($location) || ! is_string($location['name'] ?? null) || count($destinations) >= self::MAX_LOCATIONS) {
                        continue;
                    }
                    $destinations[] = new RemoteDestination(
                        externalId: $account['name'] . '/' . $location['name'],
                        name: (string) ($location['title'] ?? 'Ficha de Google'),
                        type: 'location',
                        capabilities: $this->capabilities(),
                        metadata: array_filter([
                            'address' => $this->address($location),
                            'maps_url' => $location['metadata']['mapsUri'] ?? null,
                        ]),
                    );
                }
                $pageToken = $data['nextPageToken'] ?? null;
            } while (is_string($pageToken) && $pageToken !== '' && count($destinations) < self::MAX_LOCATIONS);
        }

        return $destinations;
    }

    /**
     * Los botones disponibles son los mismos para todas las fichas: sin llamada a la red.
     */
    public function publishOptions(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        return ['call_to_action_options' => self::CTA_TYPES, 'can_post' => true, 'blocked_reason' => null];
    }

    /**
     * El botón es opcional; si se elige, debe ser válido y (salvo «Llamar») llevar un enlace.
     */
    public function optionErrors(array $options): array
    {
        $type = $options['call_to_action'] ?? null;
        if ($type === null || $type === '') {
            return [];
        }
        if (! in_array($type, self::CTA_TYPES, true)) {
            return ['Elige un botón válido para Google Business Profile.'];
        }
        $url = $options['cta_url'] ?? null;
        if ($type !== 'CALL' && (! is_string($url) || preg_match('~^https?://\S+$~i', $url) !== 1)) {
            return ['El botón de Google Business Profile necesita un enlace (https://…).'];
        }

        return [];
    }

    /**
     * Novedad «estándar» con texto, una foto opcional y el botón elegido.
     */
    public function publish(OAuthTokens $tokens, string $destinationExternalId, PublishPayload $payload, array $credentials): PublishResult
    {
        if ($payload->isStory()) {
            throw new SocialProviderException('Google Business Profile no admite historias.');
        }
        if ($payload->hasVideo()) {
            throw new SocialProviderException('Google Business Profile sólo admite fotos en las publicaciones.');
        }
        if (($problems = $this->optionErrors($payload->options)) !== []) {
            throw new SocialProviderException(implode(' ', $problems));
        }
        if (trim($payload->body) === '' && $payload->mediaUrls === []) {
            throw new SocialProviderException('La publicación de Google Business Profile necesita texto o una foto.');
        }

        $checkpoint = $payload->checkpoint;
        $done = $checkpoint->get(self::CHECKPOINT_POST);
        if (is_array($done) && is_string($done['name'] ?? null)) {
            return new PublishResult($done['name'], is_string($done['url'] ?? null) ? $done['url'] : null);
        }

        $post = ['languageCode' => 'es', 'topicType' => 'STANDARD', 'summary' => $payload->body];
        if ($payload->mediaUrls !== []) {
            // Google descarga la foto desde la URL firmada temporal del archivo.
            $post['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $payload->mediaUrls[0]]];
        }
        $cta = $payload->options['call_to_action'] ?? null;
        if (is_string($cta) && $cta !== '') {
            $post['callToAction'] = $cta === 'CALL'
                ? ['actionType' => 'CALL']
                : ['actionType' => $cta, 'url' => (string) $payload->options['cta_url']];
        }

        $data = $this->json($this->send('publicar la novedad', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->post(self::V4 . $this->locationPath($destinationExternalId) . '/localPosts', $post)), 'publicar la novedad');

        $name = (string) ($data['name'] ?? '');
        if ($name === '') {
            throw new SocialProviderException('Google no devolvió la publicación creada.');
        }
        $url = is_string($data['searchUrl'] ?? null) ? $data['searchUrl'] : null;
        // Antes que nada: si el worker muere ahora, el reintento no la publica otra vez.
        $checkpoint->put(self::CHECKPOINT_POST, ['name' => $name, 'url' => $url]);

        return new PublishResult($name, $url);
    }

    public function deleteRemotePost(OAuthTokens $tokens, string $remoteId, array $credentials): void
    {
        if (preg_match('~^accounts/[^/]+/locations/[^/]+/localPosts/[^/]+$~', $remoteId) !== 1) {
            throw new SocialProviderException('La publicación de Google no tiene un identificador válido.');
        }

        $action = 'borrar la publicación';
        $this->assertDeleted($this->send($action, fn () => $this->api($this->accessToken($tokens))->delete(self::V4 . $remoteId)), $action);
    }

    /**
     * Métricas de la ficha del último día con datos (Google las publica con 2–3
     * días de retraso): impresiones en Búsqueda y Maps, y acciones (llamadas,
     * visitas a la web, cómo llegar, mensajes y reservas). No hay seguidores.
     */
    public function fetchAccountMetrics(OAuthTokens $tokens, string $destinationExternalId, array $credentials): AccountMetrics
    {
        $to = Carbon::yesterday();
        $from = $to->copy()->subDays(6);
        $query = [
            'dailyRange.startDate.year=' . $from->year, 'dailyRange.startDate.month=' . $from->month, 'dailyRange.startDate.day=' . $from->day,
            'dailyRange.endDate.year=' . $to->year, 'dailyRange.endDate.month=' . $to->month, 'dailyRange.endDate.day=' . $to->day,
        ];
        foreach ([...self::IMPRESSIONS, ...self::ACTIONS] as $metric) {
            $query[] = 'dailyMetrics=' . $metric;
        }
        $location = 'locations/' . $this->locationId($destinationExternalId);

        $data = $this->json($this->send('leer las métricas de la ficha', fn () => $this->api($this->accessToken($tokens))
            ->get(self::PERFORMANCE . $location . ':fetchMultiDailyMetricsTimeSeries?' . implode('&', $query))), 'leer las métricas de la ficha');

        $byDate = [];
        foreach ((array) ($data['multiDailyMetricTimeSeries'] ?? []) as $group) {
            foreach ((array) (is_array($group) ? ($group['dailyMetricTimeSeries'] ?? []) : []) as $series) {
                if (! is_array($series) || ! is_string($series['dailyMetric'] ?? null)) {
                    continue;
                }
                foreach ((array) ($series['timeSeries']['datedValues'] ?? []) as $point) {
                    if (! is_array($point) || ! isset($point['date']['year'], $point['value'])) {
                        continue; // sin «value»: ese día aún no hay dato
                    }
                    $date = sprintf('%04d-%02d-%02d', (int) $point['date']['year'], (int) ($point['date']['month'] ?? 1), (int) ($point['date']['day'] ?? 1));
                    $byDate[$date][$series['dailyMetric']] = (int) $point['value'];
                }
            }
        }
        ksort($byDate);
        $latest = $byDate === [] ? [] : $byDate[array_key_last($byDate)];
        $sum = fn (array $metrics): int => array_sum(array_map(fn (string $m): int => $latest[$m] ?? 0, $metrics));
        $impressions = $sum(self::IMPRESSIONS);

        return new AccountMetrics(
            followers: 0,
            // Cada impresión ya es de una persona distinta por día y superficie.
            reach: $impressions,
            impressions: $impressions,
            engagement: $sum(self::ACTIONS),
            postsCount: 0,
        );
    }

    public function fetchPostMetrics(OAuthTokens $tokens, string $remoteId, array $credentials): PostMetrics
    {
        throw new SocialProviderException('Google ya no ofrece métricas por publicación (las retiró en 2023).');
    }

    /**
     * Reseñas de la ficha como conversaciones (una por reseña, con su respuesta si
     * la tiene). Los mensajes llevan la fecha de actualización en su id: una reseña
     * o una respuesta editadas llegan como mensajes nuevos.
     */
    public function fetchConversations(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array
    {
        $data = $this->json($this->send('leer las reseñas', fn () => $this->api($this->accessToken($tokens))
            ->get(self::V4 . $this->locationPath($destinationExternalId) . '/reviews', [
                'pageSize' => 50,
                'orderBy' => 'updateTime desc',
            ])), 'leer las reseñas');

        $threads = [];
        foreach ((array) ($data['reviews'] ?? []) as $review) {
            if (! is_array($review) || ! is_string($review['name'] ?? null) || ! is_string($review['reviewId'] ?? null)) {
                continue;
            }
            $reviewer = is_array($review['reviewer'] ?? null) ? $review['reviewer'] : [];
            $author = ($reviewer['isAnonymous'] ?? false) === true || ! is_string($reviewer['displayName'] ?? null)
                ? 'Usuario de Google'
                : $reviewer['displayName'];
            $stars = self::STARS[$review['starRating'] ?? ''] ?? 0;
            $writtenAt = Carbon::parse((string) ($review['updateTime'] ?? $review['createTime'] ?? 'now'));
            $body = trim(($stars > 0 ? str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) . "\n" : '') . (string) ($review['comment'] ?? ''));

            $messages = [new InboxMessageData(
                externalId: $review['reviewId'] . ':' . $writtenAt->toIso8601String(),
                authorName: $author,
                authorExternalId: $review['reviewId'],
                body: $body,
                direction: 'inbound',
                sentAt: $writtenAt,
            )];
            $last = $writtenAt;

            $reply = $review['reviewReply'] ?? null;
            if (is_array($reply) && is_string($reply['comment'] ?? null)) {
                $repliedAt = Carbon::parse((string) ($reply['updateTime'] ?? 'now'));
                $messages[] = new InboxMessageData(
                    externalId: $this->replyId($review['reviewId'], $repliedAt),
                    authorName: 'Respuesta del propietario',
                    authorExternalId: 'owner',
                    body: $reply['comment'],
                    direction: 'outbound',
                    sentAt: $repliedAt,
                );
                $last = $repliedAt->greaterThan($last) ? $repliedAt : $last;
            }

            $threads[] = new InboxThread(
                externalId: $review['name'],
                type: 'review',
                participantName: $author,
                participantExternalId: $review['reviewId'],
                lastMessageAt: $last,
                messages: $messages,
            );
        }

        return $threads;
    }

    /**
     * Responde la reseña (Google admite una respuesta: responder de nuevo la sustituye).
     */
    public function replyToConversation(OAuthTokens $tokens, string $conversationExternalId, string $body, array $credentials): InboxReplyResult
    {
        if (preg_match('~^accounts/[^/]+/locations/[^/]+/reviews/([^/]+)$~', $conversationExternalId, $match) !== 1) {
            throw new SocialProviderException('La reseña no tiene un identificador válido.');
        }
        if (strlen($body) > self::REPLY_MAX_BYTES) {
            throw new SocialProviderException('La respuesta a una reseña admite hasta 4096 bytes.');
        }

        $data = $this->json($this->send('responder la reseña', fn () => $this->api($this->accessToken($tokens))
            ->asJson()
            ->put(self::V4 . $conversationExternalId . '/reply', ['comment' => $body])), 'responder la reseña');

        return new InboxReplyResult($this->replyId($match[1], Carbon::parse((string) ($data['updateTime'] ?? 'now'))));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(OAuthTokens $tokens): array
    {
        $data = $this->json($this->send('listar tus cuentas', fn () => $this->api($this->accessToken($tokens))
            ->get(self::ACCOUNTS . 'accounts', ['pageSize' => 20])), 'listar tus cuentas');

        $accounts = [];
        foreach ((array) ($data['accounts'] ?? []) as $account) {
            if (is_array($account) && is_string($account['name'] ?? null) && preg_match('~^accounts/[^/]+$~', $account['name']) === 1) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * `accounts/{a}/locations/{l}` validado: el id del destino va en la ruta de la API.
     */
    private function locationPath(string $destinationExternalId): string
    {
        if (preg_match('~^accounts/[^/]+/locations/[^/]+$~', $destinationExternalId) !== 1) {
            throw new SocialProviderException('La ficha de Google no tiene un identificador válido: reconecta la cuenta.');
        }

        return $destinationExternalId;
    }

    private function locationId(string $destinationExternalId): string
    {
        return substr($this->locationPath($destinationExternalId), strrpos($destinationExternalId, '/') + 1);
    }

    private function replyId(string $reviewId, Carbon $at): string
    {
        return $reviewId . ':reply:' . $at->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function address(array $location): ?string
    {
        $address = is_array($location['storefrontAddress'] ?? null) ? $location['storefrontAddress'] : [];
        $parts = [...array_filter((array) ($address['addressLines'] ?? []), 'is_string'), $address['locality'] ?? null];
        $text = implode(', ', array_filter($parts, fn ($part) => is_string($part) && $part !== ''));

        return $text !== '' ? $text : null;
    }
}
