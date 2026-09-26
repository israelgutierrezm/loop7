<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Vectores de una petición de embeddings, en el mismo orden que las entradas.
 */
final class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public readonly array $vectors,
        public readonly string $model,
        public readonly int $tokens = 0,
    ) {
    }
}
