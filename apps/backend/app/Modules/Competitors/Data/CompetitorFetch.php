<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Data;

/**
 * Lo que una red deja leer de una cuenta pública en un momento dado.
 */
final class CompetitorFetch
{
    /**
     * @param  array<string, int>|null  $weekly  totales de los últimos 7 días (Threads)
     * @param  list<CompetitorPostData>  $posts  publicaciones recientes, si la red las da
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $handle,
        public readonly ?string $name,
        public readonly ?string $avatarUrl,
        public readonly ?string $profileUrl,
        public readonly ?int $followers,
        public readonly ?int $postsCount,
        public readonly ?array $weekly = null,
        public readonly array $posts = [],
    ) {
    }
}
