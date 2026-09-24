<?php

declare(strict_types=1);

namespace App\Modules\Ai\Database\Seeders;

use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Providers\AnthropicProvider;
use Illuminate\Database\Seeder;

/**
 * Proveedores de IA con adaptador implementado. Idempotente y NO destructivo:
 * re-ejecutarlo no cambia lo que SUPERADMIN configuró (habilitado, por defecto,
 * modelos). La lista de modelos se refresca en vivo desde el panel.
 */
class AiProviderSeeder extends Seeder
{
    /** Modelos de Anthropic sugeridos (generación actual). */
    private const ANTHROPIC_MODELS = [AnthropicProvider::DEFAULT_MODEL, 'claude-sonnet-5', 'claude-haiku-4-5'];

    public function run(): void
    {
        // El proveedor "fake" se habilita y marca por defecto fuera de producción.
        $nonProd = ! app()->environment('production');

        $providers = [
            [
                'key' => 'fake',
                'name' => 'Proveedor de prueba',
                'is_enabled' => $nonProd,
                'is_default' => $nonProd,
                'config' => ['text_model' => 'fake-text-1', 'image_model' => 'fake-image-1'],
            ],
            [
                'key' => 'openai',
                'name' => 'OpenAI',
                'is_enabled' => false,
                'is_default' => false,
                'config' => [
                    'text_model' => 'gpt-4o-mini',
                    'image_model' => 'gpt-image-1',
                    'text_models' => ['gpt-4o-mini', 'gpt-4o'],
                    'image_models' => ['gpt-image-1'],
                ],
            ],
            [
                'key' => 'anthropic',
                'name' => 'Anthropic',
                'is_enabled' => false,
                'is_default' => false,
                'config' => ['text_model' => AnthropicProvider::DEFAULT_MODEL, 'text_models' => self::ANTHROPIC_MODELS],
            ],
        ];

        foreach ($providers as $provider) {
            AiProvider::query()->firstOrCreate(['key' => $provider['key']], $provider);
        }

        // Retira proveedores sin adaptador (p. ej. registros antiguos de Gemini).
        AiProvider::query()->whereNotIn('key', array_column($providers, 'key'))->delete();

        // Los modelos Claude 3.x ya están retirados: se sustituyen por los vigentes.
        $anthropic = AiProvider::query()->where('key', 'anthropic')->first();
        $config = $anthropic->config ?? [];
        if ($anthropic !== null && str_starts_with((string) ($config['text_model'] ?? ''), 'claude-3')) {
            $anthropic->config = [...$config, 'text_model' => AnthropicProvider::DEFAULT_MODEL, 'text_models' => self::ANTHROPIC_MODELS];
            $anthropic->save();
        }
    }
}
