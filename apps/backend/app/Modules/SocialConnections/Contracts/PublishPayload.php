<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Carga de una publicación, independiente del proveedor. `mediaTypes` es
 * paralelo a `mediaUrls` ('image' | 'video'); si falta, se asume imagen.
 * `mediaFiles` (mismo orden) da acceso a los bytes para las redes que exigen
 * subirlos. `checkpoint` conserva el progreso entre reintentos del mismo target.
 * `title` es el título interno del contenido (lo usan las redes con título
 * propio, como YouTube).
 */
final class PublishPayload
{
    /**
     * @param  list<string>  $mediaUrls
     * @param  list<string>  $mediaTypes
     * @param  list<MediaFile>  $mediaFiles
     * @param  array<string, mixed>  $options  opciones de la red elegidas en la variante
     */
    public function __construct(
        public readonly string $body,
        public readonly array $mediaUrls = [],
        public readonly string $format = 'text',
        public readonly string $idempotencyKey = '',
        public readonly array $mediaTypes = [],
        public readonly PublishCheckpoint $checkpoint = new PublishCheckpoint(),
        public readonly array $mediaFiles = [],
        public readonly string $title = '',
        public readonly array $options = [],
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
