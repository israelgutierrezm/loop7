<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Carga de una publicación, independiente del proveedor. `mediaTypes` es
 * paralelo a `mediaUrls` ('image' | 'video'); si falta, se asume imagen.
 * `checkpoint` conserva el progreso entre reintentos del mismo target.
 */
final class PublishPayload
{
    /**
     * @param  list<string>  $mediaUrls
     * @param  list<string>  $mediaTypes
     */
    public function __construct(
        public readonly string $body,
        public readonly array $mediaUrls = [],
        public readonly string $format = 'text',
        public readonly string $idempotencyKey = '',
        public readonly array $mediaTypes = [],
        public readonly PublishCheckpoint $checkpoint = new PublishCheckpoint(),
    ) {
    }

    public function isVideo(int $index): bool
    {
        return ($this->mediaTypes[$index] ?? 'image') === 'video';
    }

    public function hasVideo(): bool
    {
        return in_array('video', $this->mediaTypes, true);
    }
}
