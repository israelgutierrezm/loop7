<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Carga de una publicación, independiente del proveedor.
 */
final class PublishPayload
{
    /**
     * @param  list<string>  $mediaUrls
     */
    public function __construct(
        public readonly string $body,
        public readonly array $mediaUrls = [],
        public readonly string $format = 'text',
        public readonly string $idempotencyKey = '',
    ) {
    }
}
