<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

/**
 * Resultado de leer un feed. `notModified` = el servidor respondió 304.
 *
 * @phpstan-type FeedItem array{id: string, title: string, link: string|null, summary: string, author: string|null, published_at: string|null, image: string|null}
 */
final class FeedResult
{
    /**
     * @param  list<FeedItem>  $items  en el orden del feed (normalmente, lo más nuevo primero)
     */
    public function __construct(
        public readonly bool $notModified,
        public readonly string $title = '',
        public readonly array $items = [],
        public readonly ?string $etag = null,
        public readonly ?string $lastModified = null,
    ) {
    }
}
