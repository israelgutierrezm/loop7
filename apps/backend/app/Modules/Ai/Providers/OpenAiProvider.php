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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador de OpenAI (texto con Chat Completions e imagen). Requiere `api_key`
 * (de plataforma o BYOK); sin clave lanza AiProviderNotConfiguredException.
 * Los modelos de razonamiento (o*, gpt-5*) no aceptan `temperature` y todos
 * los actuales usan `max_completion_tokens`.
 */
class OpenAiProvider implements ImageAIProviderInterface, TextAIProviderInterface
{
    private const BASE_URL = 'https://api.openai.com/v1';

    private const DEFAULT_TEXT_MODEL = 'gpt-4o-mini';

    private const DEFAULT_IMAGE_MODEL = 'gpt-image-1';

    public function generateText(TextGenerationRequest $request, array $credentials): TextGenerationResult
    {
        $apiKey = $this->apiKey($credentials);
        $model = $request->model !== '' ? $request->model : self::DEFAULT_TEXT_MODEL;

        $payload = [
            'model' => $model,
            'max_completion_tokens' => $this->isReasoningModel($model) ? max($request->maxTokens, 8000) : $request->maxTokens,
            'messages' => array_values(array_filter([
                $request->systemContext !== ''
                    ? ['role' => 'system', 'content' => $request->systemContext]
                    : null,
                ['role' => 'user', 'content' => $request->prompt],
            ])),
        ];
        if (! $this->isReasoningModel($model)) {
            $payload['temperature'] = $request->temperature;
        }

        $response = Http::withToken($apiKey)->timeout(120)->post(self::BASE_URL . '/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI devolvió un error: ' . $this->errorMessage($response));
        }

        $data = (array) $response->json();

        return new TextGenerationResult(
            text: trim((string) ($data['choices'][0]['message']['content'] ?? '')),
            model: (string) ($data['model'] ?? $model),
            inputTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
        );
    }

    public function verify(array $credentials): void
    {
        $this->listModels($credentials);
    }

    public function listModels(array $credentials): array
    {
        $response = Http::withToken($this->apiKey($credentials))->timeout(15)->get(self::BASE_URL . '/models');

        if ($response->failed()) {
            throw new RuntimeException('OpenAI rechazó la API key: ' . $this->errorMessage($response));
        }

        $ids = collect((array) $response->json('data', []))
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->sort()
            ->values();

        $image = $ids->filter(fn (string $id) => str_starts_with($id, 'gpt-image') || str_starts_with($id, 'dall-e'))->values()->all();
        $text = $ids
            ->filter(fn (string $id) => preg_match('/^(gpt-|o\d|chatgpt-)/', $id) === 1)
            ->reject(fn (string $id) => preg_match('/(image|audio|realtime|transcribe|tts|search|embedding|moderation)/', $id) === 1)
            ->values()
            ->all();

        return ['text' => $text, 'image' => $image];
    }

    public function generateImage(ImageGenerationRequest $request, array $credentials): ImageGenerationResult
    {
        $apiKey = $this->apiKey($credentials);
        $model = $request->model !== '' ? $request->model : self::DEFAULT_IMAGE_MODEL;
        $isGptImage = str_starts_with($model, 'gpt-image');

        $payload = [
            'model' => $model,
            'prompt' => $request->prompt,
            'size' => $isGptImage ? $this->gptImageSize($request->size) : $request->size,
            'n' => max(1, $request->count),
        ];

        $response = Http::withToken($apiKey)->timeout(180)->post(self::BASE_URL . '/images/generations', $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI devolvió un error: ' . $this->errorMessage($response));
        }

        $images = [];
        foreach ((array) $response->json('data', []) as $item) {
            // gpt-image devuelve base64; DALL·E devuelve una URL temporal.
            if (isset($item['b64_json'])) {
                $images[] = ['url' => 'data:image/png;base64,' . $item['b64_json'], 'b64' => (string) $item['b64_json']];
            } elseif (isset($item['url'])) {
                $images[] = ['url' => (string) $item['url']];
            }
        }

        return new ImageGenerationResult($images, $model);
    }

    private function isReasoningModel(string $model): bool
    {
        return preg_match('/^(o\d|gpt-5)/', $model) === 1;
    }

    /**
     * gpt-image admite 1024x1024, 1536x1024 y 1024x1536.
     */
    private function gptImageSize(string $size): string
    {
        return match ($size) {
            '1792x1024', '1536x1024' => '1536x1024',
            '1024x1792', '1024x1536' => '1024x1536',
            default => '1024x1024',
        };
    }

    private function errorMessage(Response $response): string
    {
        $message = (string) ($response->json('error.message') ?? '');

        return 'HTTP ' . $response->status() . ($message !== '' ? ' · ' . $message : '');
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
