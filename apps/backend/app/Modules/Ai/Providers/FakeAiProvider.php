<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\ImageAIProviderInterface;
use App\Modules\Ai\Contracts\ImageGenerationRequest;
use App\Modules\Ai\Contracts\ImageGenerationResult;
use App\Modules\Ai\Contracts\TextAIProviderInterface;
use App\Modules\Ai\Contracts\TextGenerationRequest;
use App\Modules\Ai\Contracts\TextGenerationResult;
use RuntimeException;

/**
 * Proveedor de IA simulado para desarrollo y pruebas. No hace llamadas de red:
 * compone una respuesta plausible a partir del prompt y el contexto de marca.
 * Simula un fallo si el prompt contiene el marcador [[FAIL]].
 */
class FakeAiProvider implements ImageAIProviderInterface, TextAIProviderInterface
{
    public function generateText(TextGenerationRequest $request, array $credentials): TextGenerationResult
    {
        if (str_contains($request->prompt, '[[FAIL]]')) {
            throw new RuntimeException('Fallo simulado del proveedor de IA.');
        }

        $model = $request->model !== '' ? $request->model : 'fake-text-1';
        $text = $this->composeText($request);

        // Estimación de tokens ~ palabras (suficiente para el registro de uso).
        $inputTokens = (int) ceil((mb_strlen($request->prompt) + mb_strlen($request->systemContext)) / 4);
        $outputTokens = (int) ceil(mb_strlen($text) / 4);

        return new TextGenerationResult($text, $model, $inputTokens, $outputTokens);
    }

    public function generateImage(ImageGenerationRequest $request, array $credentials): ImageGenerationResult
    {
        if (str_contains($request->prompt, '[[FAIL]]')) {
            throw new RuntimeException('Fallo simulado del proveedor de IA.');
        }

        $model = $request->model !== '' ? $request->model : 'fake-image-1';
        $images = [];
        for ($i = 0; $i < max(1, $request->count); $i++) {
            $images[] = ['url' => $this->placeholder($request->prompt, $request->size)];
        }

        return new ImageGenerationResult($images, $model);
    }

    private function composeText(TextGenerationRequest $request): string
    {
        $brief = trim($request->prompt);
        $lines = [
            '✨ ' . mb_strimwidth($brief, 0, 90, '…'),
            '',
            'Descubre cómo damos vida a esta idea con un enfoque cercano y profesional. '
                . 'Cuéntanos qué opinas y súmate a la conversación.',
            '',
            '👉 Escríbenos y te acompañamos en cada paso.',
            '',
            '#Marketing #RedesSociales #Contenido',
        ];

        return implode("\n", $lines);
    }

    private function placeholder(string $prompt, string $size): string
    {
        [$w, $h] = array_pad(array_map('intval', explode('x', $size)), 2, 1024);
        $w = $w > 0 ? $w : 1024;
        $h = $h > 0 ? $h : 1024;
        $label = htmlspecialchars(mb_strimwidth($prompt, 0, 40, '…'), ENT_QUOTES);
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="{$h}" viewBox="0 0 {$w} {$h}">
<rect width="100%" height="100%" fill="#6366f1"/>
<text x="50%" y="50%" fill="#ffffff" font-family="sans-serif" font-size="28" text-anchor="middle" dominant-baseline="middle">{$label}</text>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
