<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Resultado de una publicación en el proveedor.
 */
final class PublishResult
{
    public function __construct(
        public readonly string $remoteId,
        public readonly ?string $remoteUrl = null,
    ) {
    }
}
