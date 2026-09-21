<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Contrato de proveedor de IA de imagen. El dominio no se acopla a un proveedor
 * concreto (CLAUDE.md / docs/07).
 */
interface ImageAIProviderInterface
{
    /**
     * @param  array<string, string>  $credentials  claves del proveedor (plataforma o BYOK)
     */
    public function generateImage(ImageGenerationRequest $request, array $credentials): ImageGenerationResult;
}
