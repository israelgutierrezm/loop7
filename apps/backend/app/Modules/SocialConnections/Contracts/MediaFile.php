<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

use Closure;
use RuntimeException;

/**
 * Archivo de un medio a publicar. Las redes que descargan de una URL pública
 * (Meta, Threads) usan `$url`; las que exigen subir los bytes (LinkedIn, X,
 * YouTube, TikTok) abren el archivo como stream, sin pasar por HTTP ni cargarlo
 * entero en memoria.
 */
final class MediaFile
{
    /**
     * @param  Closure(): resource  $opener
     */
    public function __construct(
        public readonly string $url,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        private readonly Closure $opener,
    ) {
    }

    /**
     * @return resource
     */
    public function open()
    {
        $stream = ($this->opener)();
        if (! is_resource($stream)) {
            throw new RuntimeException('No se pudo leer el archivo del medio.');
        }

        return $stream;
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }
}
