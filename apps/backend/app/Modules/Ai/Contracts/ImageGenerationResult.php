<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Resultado de una generación de imagen. Cada elemento de `images` es un mapa
 * con `url` (puede ser un data URI) y opcionalmente `b64`.
 */
final class ImageGenerationResult
{
    /**
     * @param  list<array{url: string, b64?: string}>  $images
     */
    public function __construct(
        public readonly array $images,
        public readonly string $model,
    ) {
    }
}
