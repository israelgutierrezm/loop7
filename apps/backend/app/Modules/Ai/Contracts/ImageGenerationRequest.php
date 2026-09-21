<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Petición de generación de imagen, independiente del proveedor.
 */
final class ImageGenerationRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $size = '1024x1024',
        public readonly string $model = '',
        public readonly int $count = 1,
    ) {
    }
}
