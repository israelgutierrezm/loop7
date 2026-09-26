<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\EmbeddingProviderInterface;
use App\Modules\Ai\Contracts\EmbeddingResult;
use App\Modules\Ai\Contracts\ImageAIProviderInterface;
use App\Modules\Ai\Contracts\ImageGenerationRequest;
use App\Modules\Ai\Contracts\ImageGenerationResult;
use App\Modules\Ai\Contracts\TextAIProviderInterface;
use App\Modules\Ai\Contracts\TextGenerationRequest;
use App\Modules\Ai\Contracts\TextGenerationResult;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proveedor de IA simulado para desarrollo y pruebas. No hace llamadas de red:
 * compone una respuesta plausible a partir del prompt y el contexto de marca.
 * Simula un fallo si el prompt contiene el marcador [[FAIL]].
 *
 * Sus embeddings son deterministas (hashing de palabras normalizadas): textos
 * que comparten términos quedan cerca, suficiente para probar el RAG sin red.
 */
class FakeAiProvider implements EmbeddingProviderInterface, ImageAIProviderInterface, TextAIProviderInterface
{
    public function defaultEmbeddingModel(): string
    {
        return 'fake-embedding-1';
    }

    public function embed(array $inputs, array $credentials, string $model, int $dimensions): EmbeddingResult
    {
        $vectors = [];
        foreach ($inputs as $input) {
            if (str_contains($input, '[[FAIL]]')) {
                throw new RuntimeException('Fallo simulado del proveedor de IA.');
            }
            $vector = array_fill(0, $dimensions, 0.0);
            foreach ($this->terms($input) as $term) {
                $hash = crc32($term);
                $vector[$hash % $dimensions] += ($hash & 1) === 1 ? 1.0 : -1.0;
            }
            $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));
            $vectors[] = $norm > 0 ? array_map(fn (float $v) => $v / $norm, $vector) : $vector;
        }

        return new EmbeddingResult($vectors, $model !== '' ? $model : $this->defaultEmbeddingModel(), (int) ceil(mb_strlen(implode(' ', $inputs)) / 4));
    }

    /**
     * Palabras normalizadas (sin acentos, recortadas a 6 letras como raíz tosca).
     *
     * @return list<string>
     */
    private function terms(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text))) ?: [];

        return array_values(array_map(
            fn (string $w) => mb_substr($w, 0, 6),
            array_filter($words, fn (string $w) => mb_strlen($w) >= 3),
        ));
    }
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

    public function verify(array $credentials): void
    {
        // El proveedor de prueba siempre está disponible.
    }

    public function listModels(array $credentials): array
    {
        return ['text' => ['fake-text-1'], 'image' => ['fake-image-1']];
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

    /**
     * PNG de muestra (color de marca + texto del prompt) generado con GD, a un
     * tamaño reducido proporcional al solicitado.
     */
    private function placeholder(string $prompt, string $size): string
    {
        [$w, $h] = array_pad(array_map('intval', explode('x', $size)), 2, 1024);
        $w = max(64, intdiv($w > 0 ? $w : 1024, 4));
        $h = max(64, intdiv($h > 0 ? $h : 1024, 4));

        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 99, 102, 241));
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $label = mb_strimwidth(preg_replace('/[^\x20-\x7E]/', '', $prompt) ?? '', 0, (int) floor($w / 8) - 2, '...');
        imagestring($image, 3, 8, intdiv($h, 2) - 6, $label, $white);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
