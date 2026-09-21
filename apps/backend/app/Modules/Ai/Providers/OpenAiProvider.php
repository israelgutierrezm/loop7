<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\ImageAIProviderInterface;
use App\Modules\Ai\Contracts\ImageGenerationRequest;
use App\Modules\Ai\Contracts\ImageGenerationResult;
use App\Modules\Ai\Contracts\TextAIProviderInterface;
use App\Modules\Ai\Contracts\TextGenerationRequest;
use App\Modules\Ai\Contracts\TextGenerationResult;
use App\Modules\Ai\Exceptions\AiProviderNotConfiguredException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador real (skeleton) para OpenAI. Requiere `api_key` en las credenciales
 * (de plataforma o BYOK). Sin clave lanza AiProviderNotConfiguredException, por
 * lo que en pruebas/dev se usa el proveedor `fake`.
 */
class OpenAiProvider implements ImageAIProviderInterface, TextAIProviderInterface
{
    private const BASE_URL = 'https://api.openai.com/v1';

    public function generateText(TextGenerationRequest $request, array $credentials): TextGenerationResult
    {
        $apiKey = $this->apiKey($credentials);
        $model = $request->model !== '' ? $request->model : 'gpt-4o-mini';

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post(self::BASE_URL . '/chat/completions', [
                'model' => $model,
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
                'messages' => array_values(array_filter([
                    $request->systemContext !== ''
                        ? ['role' => 'system', 'content' => $request->systemContext]
                        : null,
                    ['role' => 'user', 'content' => $request->prompt],
                ])),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI devolvió un error: ' . $response->status());
        }

        $data = $response->json();

        return new TextGenerationResult(
            text: (string) ($data['choices'][0]['message']['content'] ?? ''),
            model: (string) ($data['model'] ?? $model),
            inputTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
        );
    }

    public function generateImage(ImageGenerationRequest $request, array $credentials): ImageGenerationResult
    {
        $apiKey = $this->apiKey($credentials);
        $model = $request->model !== '' ? $request->model : 'dall-e-3';

        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->post(self::BASE_URL . '/images/generations', [
                'model' => $model,
                'prompt' => $request->prompt,
                'size' => $request->size,
                'n' => max(1, $request->count),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI devolvió un error: ' . $response->status());
        }

        $images = [];
        foreach ($response->json('data', []) as $item) {
            $images[] = array_filter([
                'url' => $item['url'] ?? null,
                'b64' => $item['b64_json'] ?? null,
            ]);
        }

        /** @var list<array{url: string, b64?: string}> $images */
        return new ImageGenerationResult($images, $model);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function apiKey(array $credentials): string
    {
        $key = $credentials['api_key'] ?? '';
        if ($key === '') {
            throw new AiProviderNotConfiguredException('Falta la API key de OpenAI.');
        }

        return $key;
    }
}
