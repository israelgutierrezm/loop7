<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Resultado de una generación de texto. Los contadores de tokens sirven para
 * el registro de uso y la estimación de coste (docs/07 AI Usage).
 */
final class TextGenerationResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
