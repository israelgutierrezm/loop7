<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Métricas a nivel de publicación, independientes del proveedor (docs/05).
 */
final class PostMetrics
{
    public function __construct(
        public readonly int $impressions = 0,
        public readonly int $reach = 0,
        public readonly int $likes = 0,
        public readonly int $comments = 0,
        public readonly int $shares = 0,
        public readonly int $clicks = 0,
    ) {
    }

    /**
     * Interacciones totales (base de la tasa de engagement).
     */
    public function engagement(): int
    {
        return $this->likes + $this->comments + $this->shares + $this->clicks;
    }
}
