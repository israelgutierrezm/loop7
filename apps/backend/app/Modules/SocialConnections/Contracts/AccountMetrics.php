<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Métricas a nivel de cuenta/página, independientes del proveedor (docs/05).
 */
final class AccountMetrics
{
    public function __construct(
        public readonly int $followers = 0,
        public readonly int $reach = 0,
        public readonly int $impressions = 0,
        public readonly int $engagement = 0,
        public readonly int $postsCount = 0,
    ) {
    }
}
