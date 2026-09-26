<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\EmbeddingProviderInterface;

/**
 * Espacio de embeddings con el que trabaja una organización: proveedor, modelo
 * y dimensiones (vectores de espacios distintos no son comparables). Lleva las
 * credenciales para usarlas, pero nunca las expone ni las serializa.
 */
final class EmbeddingSpace
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $dimensions,
        public readonly bool $byok,
        private readonly EmbeddingProviderInterface $adapter,
        private readonly array $credentials,
    ) {
    }

    /** Identificador que se guarda con cada vector (sin secretos). */
    public function id(): string
    {
        return "{$this->provider}:{$this->model}:{$this->dimensions}";
    }

    /** Similitud mínima para considerar relevante un fragmento en este espacio. */
    public function minScore(): float
    {
        return $this->provider === 'fake' ? 0.15 : 0.25;
    }

    public function isSemantic(): bool
    {
        return $this->provider !== 'fake';
    }

    /**
     * @param  list<string>  $inputs
     * @return array{vectors: list<list<float>>, tokens: int}
     */
    public function embed(array $inputs): array
    {
        $result = $this->adapter->embed($inputs, $this->credentials, $this->model, $this->dimensions);

        return ['vectors' => $result->vectors, 'tokens' => $result->tokens];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id(), 'byok' => $this->byok];
    }
}
