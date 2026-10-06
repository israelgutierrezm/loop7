<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Sources;

use App\Modules\Competitors\Contracts\CompetitorSource;
use App\Modules\Competitors\Data\CompetitorFetch;
use App\Modules\Competitors\Data\CompetitorViewer;
use App\Modules\Competitors\Exceptions\CompetitorNotFoundException;
use App\Modules\Competitors\Exceptions\CompetitorSourceException;
use App\Modules\SocialConnections\Providers\Meta\MetaGraph;

/**
 * Páginas de Facebook con «Page Public Metadata Access»: sólo seguidores y
 * «me gusta» de la página (las publicaciones de páginas ajenas exigen otro
 * permiso de Meta). La app necesita esa función aprobada en la revisión de Meta.
 */
final class FacebookCompetitorSource implements CompetitorSource
{
    public function key(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    public function handleHint(): string
    {
        return 'Nombre de usuario de la página o su URL (facebook.com/pagina)';
    }

    public function providesPosts(): bool
    {
        return false;
    }

    public function normalizeHandle(string $input): ?string
    {
        // facebook.com/profile.php?id=123 → 123
        if (preg_match('#facebook\.com/profile\.php\?(?:[^\#\s]*&)?id=(\d{5,20})#i', $input, $m) === 1) {
            return $m[1];
        }

        return Handles::username($input, ['facebook.com', 'fb.com'], '/^[a-z0-9.\-]{2,100}$/');
    }

    public function fetch(string $handle, CompetitorViewer $viewer): CompetitorFetch
    {
        $graph = MetaGraph::fromCredentials($viewer->credentials);
        [$status, $json] = MetaPublicReader::get($graph->url(rawurlencode($handle)), [
            'fields' => 'id,name,username,fan_count,followers_count,link,picture.type(large){url}',
            'access_token' => $viewer->token,
        ], 'Facebook');

        if ($status >= 400) {
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $code = (int) ($error['code'] ?? 0);
            if ($code === 803 || ($code === 100 && (int) ($error['error_subcode'] ?? 0) === 33) || $status === 404) {
                throw new CompetitorNotFoundException("No encontramos la página «{$handle}» en Facebook.");
            }
            if ($code === 10 || $code === 200) {
                throw new CompetitorSourceException('La app de Meta necesita la función «Page Public Metadata Access» (aprobada en la revisión de Meta) para leer páginas que no administras.');
            }

            throw MetaPublicReader::failure($error, $status, 'Facebook');
        }

        $followers = $json['followers_count'] ?? $json['fan_count'] ?? null;

        return new CompetitorFetch(
            externalId: (string) ($json['id'] ?? $handle),
            handle: isset($json['username']) ? mb_strtolower((string) $json['username']) : $handle,
            name: isset($json['name']) ? (string) $json['name'] : null,
            avatarUrl: is_string($json['picture']['data']['url'] ?? null) ? $json['picture']['data']['url'] : null,
            profileUrl: isset($json['link']) ? (string) $json['link'] : 'https://www.facebook.com/' . rawurlencode($handle),
            followers: is_numeric($followers) ? (int) $followers : null,
            postsCount: null,
        );
    }
}
