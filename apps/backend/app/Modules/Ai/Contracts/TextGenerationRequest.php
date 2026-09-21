<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Petición de generación de texto, independiente del proveedor.
 *
 * `systemContext` contiene el Brand Brain compuesto (voz, tono, audiencia,
 * oferta…). Nunca debe mezclar contexto de otra Organization/Brand.
 */
final class TextGenerationRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $systemContext = '',
        public readonly string $model = '',
        public readonly int $maxTokens = 800,
        public readonly float $temperature = 0.7,
        public readonly string $locale = 'es',
    ) {
    }
}
