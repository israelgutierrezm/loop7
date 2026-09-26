<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Contrato de proveedor de embeddings (docs/07: contratos separados por
 * capacidad). Convierte textos en vectores para la búsqueda semántica del
 * Brand Brain (RAG). El dominio no se acopla a un proveedor concreto.
 */
interface EmbeddingProviderInterface
{
    /**
     * @param  list<string>  $inputs
     * @param  array<string, string>  $credentials  claves del proveedor (plataforma o BYOK)
     */
    public function embed(array $inputs, array $credentials, string $model, int $dimensions): EmbeddingResult;

    public function defaultEmbeddingModel(): string;
}
