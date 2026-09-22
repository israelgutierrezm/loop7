<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\TextAIProviderInterface;
use App\Modules\Ai\Contracts\TextGenerationRequest;
use App\Modules\Ai\Contracts\TextGenerationResult;
use App\Modules\Ai\Exceptions\AiProviderNotConfiguredException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador real (skeleton) para Anthropic (Claude). Sólo texto. Requiere
 * `api_key`; sin ella lanza AiProviderNotConfiguredException.
 */
class AnthropicProvider implements TextAIProviderInterface
{
    private const BASE_URL = 'https://api.anthropic.com/v1';

    private const VERSION = '2023-06-01';

    public function generateText(TextGenerationRequest $request, array $credentials): TextGenerationResult
    {
        $apiKey = $this->apiKey($credentials);
        $model = $request->model !== '' ? $request->model : 'claude-3-5-sonnet-latest';

        $payload = [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];
        if ($request->systemContext !== '') {
            $payload['system'] = $request->systemContext;
        }

        $response = Http::withHeaders($this->headers($apiKey))
            ->timeout(60)
            ->post(self::BASE_URL . '/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic devolvió un error: ' . $response->status());
        }

        $data = $response->json();

        return new TextGenerationResult(
            text: (string) ($data['content'][0]['text'] ?? ''),
            model: (string) ($data['model'] ?? $model),
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
        );
    }

    public function verify(array $credentials): void
    {
        $apiKey = $this->apiKey($credentials);
        $response = Http::withHeaders($this->headers($apiKey))->timeout(15)->get(self::BASE_URL . '/models');

        if ($response->failed()) {
            throw new RuntimeException('Anthropic rechazó la API key (HTTP ' . $response->status() . ').');
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::VERSION,
            'content-type' => 'application/json',
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function apiKey(array $credentials): string
    {
        $key = $credentials['api_key'] ?? '';
        if ($key === '') {
            throw new AiProviderNotConfiguredException('Falta la API key de Anthropic.');
        }

        return $key;
    }
}
