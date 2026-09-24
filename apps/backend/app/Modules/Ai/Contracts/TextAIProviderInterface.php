<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Contrato de proveedor de IA de texto. El dominio no se acopla a OpenAI,
 * Anthropic, etc. (CLAUDE.md / docs/07).
 */
interface TextAIProviderInterface
{
    /**
     * @param  array<string, string>  $credentials  claves del proveedor (plataforma o BYOK)
     */
    public function generateText(TextGenerationRequest $request, array $credentials): TextGenerationResult;

    /**
     * Verifica que las credenciales son válidas (para "probar conexión").
     * Lanza una excepción si la verificación falla.
     *
     * @param  array<string, string>  $credentials
     */
    public function verify(array $credentials): void;

    /**
     * Modelos disponibles para la cuenta (para elegirlos en SUPERADMIN sin
     * depender de una lista fija que envejece).
     *
     * @param  array<string, string>  $credentials
     * @return array{text: list<string>, image: list<string>}
     */
    public function listModels(array $credentials): array;
}
