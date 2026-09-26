<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\EmbeddingProviderInterface;
use App\Modules\Ai\Contracts\ImageAIProviderInterface;
use App\Modules\Ai\Contracts\TextAIProviderInterface;
use App\Modules\Ai\Enums\AiModality;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Providers\AnthropicProvider;
use App\Modules\Ai\Providers\FakeAiProvider;
use App\Modules\Ai\Providers\OpenAiProvider;
use Illuminate\Support\Collection;

/**
 * Registro de adaptadores de IA disponibles y resolución del proveedor activo
 * por modalidad. Sigue el mismo patrón que SocialProviderManager/GatewayManager.
 */
class AiProviderManager
{
    /** @var array<string, TextAIProviderInterface> */
    private array $textAdapters;

    /** @var array<string, ImageAIProviderInterface> */
    private array $imageAdapters;

    /** @var array<string, EmbeddingProviderInterface> */
    private array $embeddingAdapters;

    public function __construct()
    {
        $fake = new FakeAiProvider();
        $openai = new OpenAiProvider();
        $anthropic = new AnthropicProvider();

        // Texto: fake, OpenAI y Anthropic. Imagen y embeddings: fake y OpenAI
        // (Anthropic no genera imágenes ni ofrece embeddings).
        $this->textAdapters = ['fake' => $fake, 'openai' => $openai, 'anthropic' => $anthropic];
        $this->imageAdapters = ['fake' => $fake, 'openai' => $openai];
        $this->embeddingAdapters = ['fake' => $fake, 'openai' => $openai];
    }

    public function embeddingAdapter(string $key): ?EmbeddingProviderInterface
    {
        return $this->embeddingAdapters[$key] ?? null;
    }

    public function textAdapter(string $key): ?TextAIProviderInterface
    {
        return $this->textAdapters[$key] ?? null;
    }

    public function imageAdapter(string $key): ?ImageAIProviderInterface
    {
        return $this->imageAdapters[$key] ?? null;
    }

    public function record(string $key): ?AiProvider
    {
        return AiProvider::query()->where('key', $key)->first();
    }

    /**
     * Proveedor a usar para una modalidad: el marcado por defecto si está
     * habilitado y tiene adaptador; si no, el primero habilitado con adaptador.
     */
    public function providerFor(AiModality $modality): ?AiProvider
    {
        $adapters = $modality === AiModality::IMAGE ? $this->imageAdapters : $this->textAdapters;

        $enabled = AiProvider::query()
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->filter(fn (AiProvider $p) => isset($adapters[$p->key]));

        return $enabled->first();
    }

    /**
     * Proveedores habilitados con adaptador disponible (cualquier modalidad).
     *
     * @return Collection<int, AiProvider>
     */
    public function enabled(): Collection
    {
        return AiProvider::query()
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (AiProvider $p) => isset($this->textAdapters[$p->key]) || isset($this->imageAdapters[$p->key]))
            ->values();
    }

    public function isEnabled(string $key): bool
    {
        return AiProvider::query()->where('key', $key)->where('is_enabled', true)->exists();
    }
}
