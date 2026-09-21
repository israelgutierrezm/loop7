<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Contrato de proveedor de IA de texto. El dominio no se acopla a OpenAI,
 * Anthropic, Gemini, etc. (CLAUDE.md / docs/07).
 */
interface TextAIProviderInterface
{
    /**
     * @param  array<string, string>  $credentials  claves del proveedor (plataforma o BYOK)
     */
    public function generateText(TextGenerationRequest $request, array $credentials): TextGenerationResult;
}
