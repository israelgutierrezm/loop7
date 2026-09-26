<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Brands\Models\Brand;

/**
 * Fuente de conocimiento de una marca para enriquecer el contexto de la IA
 * (RAG, docs/07). La implementa el módulo Knowledge; la IA no depende de él.
 */
interface KnowledgeSource
{
    /**
     * Fragmentos relevantes para la petición, ya formateados para el contexto
     * del sistema (cadena vacía si no hay nada útil).
     */
    public function promptContext(Brand $brand, string $query): string;
}
