<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Sources;

use App\Modules\Competitors\Contracts\CompetitorSource;
use App\Modules\Competitors\Data\CompetitorFetch;
use App\Modules\Competitors\Data\CompetitorPostData;
use App\Modules\Competitors\Data\CompetitorViewer;
use App\Modules\Competitors\Exceptions\CompetitorNotFoundException;
use App\Modules\SocialConnections\Providers\Meta\MetaGraph;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Instagram con Business Discovery (API de Instagram con inicio de sesión de
 * Facebook): seguidores, publicaciones y sus «me gusta», comentarios y vistas de
 * otras cuentas profesionales. Se consulta desde una cuenta propia conectada.
 */
final class InstagramCompetitorSource implements CompetitorSource
{
    private const PROFILE = 'id,username,name,profile_picture_url,followers_count,media_count';

    private const MEDIA = 'id,caption,comments_count,like_count,timestamp,media_type,media_product_type,permalink,thumbnail_url,media_url';

    private const POSTS = 25;

    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    public function handleHint(): string
    {
        return '@usuario de una cuenta profesional (empresa o creador)';
    }

    public function providesPosts(): bool
    {
        return true;
    }

    public function normalizeHandle(string $input): ?string
    {
        return Handles::username($input, ['instagram.com']);
    }

    public function fetch(string $handle, CompetitorViewer $viewer): CompetitorFetch
    {
        $data = $this->discover(MetaGraph::fromCredentials($viewer->credentials), $handle, $viewer, true);
        $username = (string) ($data['username'] ?? $handle);

        return new CompetitorFetch(
            externalId: (string) ($data['id'] ?? ''),
            handle: $username,
            name: isset($data['name']) ? (string) $data['name'] : null,
            avatarUrl: isset($data['profile_picture_url']) ? (string) $data['profile_picture_url'] : null,
            profileUrl: 'https://www.instagram.com/' . rawurlencode($username) . '/',
            followers: isset($data['followers_count']) ? (int) $data['followers_count'] : null,
            postsCount: isset($data['media_count']) ? (int) $data['media_count'] : null,
            posts: array_map(fn (array $m): CompetitorPostData => $this->post($m), array_values(array_filter(
                (array) ($data['media']['data'] ?? []),
                fn ($m): bool => is_array($m) && isset($m['id']),
            ))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(MetaGraph $graph, string $handle, CompetitorViewer $viewer, bool $withViews): array
    {
        // El nombre ya está normalizado (letras, números, «.» y «_»): seguro dentro de «fields».
        $media = self::MEDIA . ($withViews ? ',view_count' : '');
        [$status, $json] = MetaPublicReader::get($graph->url($viewer->externalId), [
            'fields' => 'business_discovery.username(' . $handle . '){' . self::PROFILE . ',media.limit(' . self::POSTS . '){' . $media . '}}',
            'access_token' => $viewer->token,
        ], 'Instagram');

        if ($status < 400) {
            return is_array($json['business_discovery'] ?? null) ? $json['business_discovery'] : [];
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = (int) ($error['code'] ?? 0);
        // Versiones de la API sin «view_count»: se repite sin ese campo.
        if ($withViews && $code === 100 && str_contains((string) ($error['message'] ?? ''), 'view_count')) {
            return $this->discover($graph, $handle, $viewer, false);
        }
        // 110 / 2207013: no existe o no es una cuenta profesional.
        if ($code === 110 || (int) ($error['error_subcode'] ?? 0) === 2207013) {
            throw new CompetitorNotFoundException("No encontramos @{$handle} en Instagram o no es una cuenta profesional (empresa o creador).");
        }

        throw MetaPublicReader::failure($error, $status, 'Instagram');
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function post(array $m): CompetitorPostData
    {
        $type = match (true) {
            ($m['media_product_type'] ?? null) === 'REELS' => 'reel',
            ($m['media_type'] ?? null) === 'CAROUSEL_ALBUM' => 'carousel',
            ($m['media_type'] ?? null) === 'VIDEO' => 'video',
            default => 'image',
        };
        $count = fn (string $key): ?int => isset($m[$key]) && is_numeric($m[$key]) ? (int) $m[$key] : null;

        return new CompetitorPostData(
            externalId: (string) $m['id'],
            publishedAt: isset($m['timestamp']) ? CarbonImmutable::parse((string) $m['timestamp']) : null,
            type: $type,
            caption: isset($m['caption']) ? Str::limit(trim((string) $m['caption']), 480) : null,
            permalink: isset($m['permalink']) ? (string) $m['permalink'] : null,
            thumbnailUrl: isset($m['thumbnail_url']) ? (string) $m['thumbnail_url'] : (isset($m['media_url']) ? (string) $m['media_url'] : null),
            // Si la cuenta oculta los «me gusta», la red no los da.
            likes: $count('like_count'),
            comments: $count('comments_count'),
            views: $count('view_count'),
        );
    }
}
