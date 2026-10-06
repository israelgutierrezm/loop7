<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Data;

use Carbon\CarbonImmutable;

/**
 * Publicación pública de una cuenta de la competencia, tal como la da la red.
 */
final class CompetitorPostData
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?CarbonImmutable $publishedAt,
        public readonly ?string $type,
        public readonly ?string $caption,
        public readonly ?string $permalink,
        public readonly ?string $thumbnailUrl,
        public readonly ?int $likes,
        public readonly ?int $comments,
        public readonly ?int $views,
    ) {
    }
}
