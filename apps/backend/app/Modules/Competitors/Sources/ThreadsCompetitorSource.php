<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Sources;

use App\Modules\Competitors\Contracts\CompetitorSource;
use App\Modules\Competitors\Data\CompetitorFetch;
use App\Modules\Competitors\Data\CompetitorViewer;
use App\Modules\Competitors\Exceptions\CompetitorNotFoundException;
use App\Modules\Competitors\Exceptions\CompetitorSourceException;

/**
 * Threads con «Profile Discovery» (`threads_profile_discovery`): seguidores y
 * los totales de los últimos 7 días («me gusta», citas, republicaciones y
 * vistas) de perfiles públicos con al menos 100 seguidores.
 */
final class ThreadsCompetitorSource implements CompetitorSource
{
    public const SCOPE = 'threads_profile_discovery';

    private const GRAPH = 'https://graph.threads.net/';

    private const DEFAULT_VERSION = 'v1.0';

    public function key(): string
    {
        return 'threads';
    }

    public function label(): string
    {
        return 'Threads';
    }

    public function handleHint(): string
    {
        return '@usuario de un perfil público (con al menos 100 seguidores)';
    }

    public function providesPosts(): bool
    {
        return false;
    }

    public function normalizeHandle(string $input): ?string
    {
        return Handles::username($input, ['threads.net', 'threads.com']);
    }

    public function fetch(string $handle, CompetitorViewer $viewer): CompetitorFetch
    {
        $version = (string) ($viewer->credentials['api_version'] ?? '');
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            $version = self::DEFAULT_VERSION;
        }

        [$status, $json] = MetaPublicReader::get(self::GRAPH . $version . '/profile_lookup', [
            'username' => $handle,
            'fields' => 'username,name,profile_picture_url,follower_count,likes_count,quotes_count,reposts_count,views_count,is_verified',
            'access_token' => $viewer->token,
        ], 'Threads');

        if ($status >= 400) {
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $code = (int) ($error['code'] ?? 0);
            if ($code === 10 || $code === 200) {
                throw new CompetitorSourceException('Falta el permiso «threads_profile_discovery»: actívalo en SUPERADMIN → Redes sociales → Threads y reconecta la cuenta.');
            }
            if (in_array($code, [24, 100, 110], true) || $status === 404) {
                throw new CompetitorNotFoundException("No encontramos @{$handle} en Threads (debe ser un perfil público con al menos 100 seguidores).");
            }

            throw MetaPublicReader::failure($error, $status, 'Threads');
        }

        $count = fn (string $key): int => is_numeric($json[$key] ?? null) ? (int) $json[$key] : 0;
        $username = mb_strtolower((string) ($json['username'] ?? $handle));

        return new CompetitorFetch(
            externalId: $username,
            handle: $username,
            name: isset($json['name']) ? (string) $json['name'] : null,
            avatarUrl: isset($json['profile_picture_url']) ? (string) $json['profile_picture_url'] : null,
            profileUrl: 'https://www.threads.net/@' . rawurlencode($username),
            followers: is_numeric($json['follower_count'] ?? null) ? (int) $json['follower_count'] : null,
            postsCount: null,
            weekly: [
                'likes' => $count('likes_count'),
                'quotes' => $count('quotes_count'),
                'reposts' => $count('reposts_count'),
                'views' => $count('views_count'),
            ],
        );
    }
}
