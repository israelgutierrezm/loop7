<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Límites de publicación del proveedor, para avisar al programar (y no
 * descubrirlo cuando la red rechaza la publicación): caracteres del texto y
 * número de archivos por publicación (en total, imágenes y videos).
 */
interface HasPublishingLimits
{
    /**
     * @return array{text?: int, media?: int, images?: int, videos?: int}
     */
    public function publishingLimits(): array;
}
