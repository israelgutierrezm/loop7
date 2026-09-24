<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\TextAIProviderInterface;
use App\Modules\Ai\Contracts\TextGenerationRequest;
use App\Modules\Ai\Contracts\TextGenerationResult;
use App\Modules\Ai\Exceptions\AiProviderNotConfiguredException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador de Anthropic (Claude) sobre la Messages API. Sólo texto. Requiere
 * `api_key`; sin ella lanza AiProviderNotConfiguredException.
 *
 * Los modelos vigentes rechazan los parámetros de muestreo (`temperature`) y
 * razonan por defecto (bloques `thinking` antes del texto), por eso no se envía
 * temperatura, se deja margen amplio de `max_tokens` y se concatenan sólo los
 * bloques de texto de la respuesta.
 */
class AnthropicProvider implements TextAIProviderInterface
{
    public const DEFAULT_MODEL = 'claude-opus-5';

    private const BASE_URL = 'https://api.anthropic.com/v1';

    private const VERSION = '2023-06-01';

    /** Margen de salida: el razonamiento también consume max_tokens. */
    private const MIN_MAX_TOKENS = 16000;

    /** Modelos que admiten el reintento en servidor ante un rechazo por políticas. */
    private const FALLBACK_MODELS = ['claude-opus-5', 'claude-fable-5', 'claude-fable-5-1'];

    public function generateText(TextGenerationRequest $request, array $credentials): TextGenerationResult
    {
        $apiKey = $this->apiKey($credentials);
        $model = $request->model !== '' ? $request->model : self::DEFAULT_MODEL;

        $payload = [
            'model' => $model,
            'max_tokens' => max($request->maxTokens, self::MIN_MAX_TOKENS),
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];
        if ($request->systemContext !== '') {
            $payload['system'] = $request->systemContext;
        }

        $headers = $this->headers($apiKey);
        if (in_array($model, self::FALLBACK_MODELS, true)) {
            // Si los clasificadores declinan la petición, la API la reintenta con el
            // modelo recomendado según la categoría en vez de devolver el rechazo.
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $payload['fallbacks'] = 'default';
        }

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post(self::BASE_URL . '/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic devolvió un error: ' . $this->errorMessage($response));
        }

        $data = (array) $response->json();

        if (($data['stop_reason'] ?? null) === 'refusal') {
            $explanation = (string) ($data['stop_details']['explanation'] ?? '');
            throw new RuntimeException('Claude declinó generar este contenido.' . ($explanation !== '' ? ' ' . $explanation : ''));
        }

        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return new TextGenerationResult(
            text: trim($text),
            model: (string) ($data['model'] ?? $model),
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
        );
    }

    public function verify(array $credentials): void
    {
        $this->listModels($credentials);
    }

    public function listModels(array $credentials): array
    {
        $response = Http::withHeaders($this->headers($this->apiKey($credentials)))
            ->timeout(15)
            ->get(self::BASE_URL . '/models', ['limit' => 100]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic rechazó la API key: ' . $this->errorMessage($response));
        }

        $ids = collect((array) $response->json('data', []))
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        return ['text' => $ids, 'image' => []];
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
            throw new AiProviderNotConfiguredException('Falta la API key de Anthropic.');
        }

        return $key;
    }
}
