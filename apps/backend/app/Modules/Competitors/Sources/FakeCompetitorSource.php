<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Sources;

use App\Modules\Competitors\Contracts\CompetitorSource;
use App\Modules\Competitors\Data\CompetitorFetch;
use App\Modules\Competitors\Data\CompetitorPostData;
use App\Modules\Competitors\Data\CompetitorViewer;
use App\Modules\Competitors\Exceptions\CompetitorNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Fuente simulada (con el proveedor de prueba activo): datos deterministas por
 * nombre de usuario, sin red. «noexiste» simula una cuenta que no se encuentra.
 */
final class FakeCompetitorSource implements CompetitorSource
{
    private const POSTS = 8;

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Red de prueba';
    }

    public function handleHint(): string
    {
        return '@usuario (datos simulados)';
    }

    public function providesPosts(): bool
    {
        return true;
    }

    public function normalizeHandle(string $input): ?string
    {
        return Handles::username($input, ['example.com']);
    }

    public function fetch(string $handle, CompetitorViewer $viewer): CompetitorFetch
    {
        if ($handle === 'noexiste') {
            throw new CompetitorNotFoundException("No encontramos @{$handle} en la red de prueba.");
        }

        $seed = crc32($handle);
        $today = CarbonImmutable::today();
        // Crece cada día: el gráfico de seguidores tiene forma.
        $followers = 2000 + $seed % 48000 + (5 + $seed % 40) * $today->dayOfYear;

        $posts = [];
        for ($i = 0; $i < self::POSTS; $i++) {
            $posts[] = new CompetitorPostData(
                externalId: "fake-{$handle}-{$i}",
                publishedAt: $today->subDays($i * 3 + 1)->setTime(12 + $i % 6, 0),
                type: ['image', 'reel', 'carousel'][$i % 3],
                caption: 'Publicación ' . ($i + 1) . ' de ' . Str::title(str_replace(['.', '_'], ' ', $handle)),
                permalink: null,
                thumbnailUrl: null,
                likes: 50 + ($seed + $i * 37) % 400,
                comments: 3 + ($seed + $i * 11) % 40,
                views: $i % 3 === 1 ? 900 + ($seed + $i * 53) % 4000 : null,
            );
        }

        return new CompetitorFetch(
            externalId: 'fake-' . $handle,
            handle: $handle,
            name: Str::title(str_replace(['.', '_'], ' ', $handle)),
            avatarUrl: null,
            profileUrl: null,
            followers: $followers,
            postsCount: 120 + $seed % 300,
            posts: $posts,
        );
    }
}
