<?php

declare(strict_types=1);

namespace App\Modules\Ai\Database\Seeders;

use App\Modules\Ai\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiProviderSeeder extends Seeder
{
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
                    'image_model' => 'dall-e-3',
                    'text_models' => ['gpt-4o-mini', 'gpt-4o'],
                    'image_models' => ['dall-e-3'],
                ],
            ],
            [
                'key' => 'anthropic',
                'name' => 'Anthropic',
                'is_enabled' => false,
                'is_default' => false,
                'config' => ['text_model' => 'claude-3-5-sonnet', 'text_models' => ['claude-3-5-sonnet']],
            ],
            [
                'key' => 'gemini',
                'name' => 'Google Gemini',
                'is_enabled' => false,
                'is_default' => false,
                'config' => ['text_model' => 'gemini-1.5-pro', 'text_models' => ['gemini-1.5-pro']],
            ],
        ];

        foreach ($providers as $provider) {
            AiProvider::query()->updateOrCreate(
                ['key' => $provider['key']],
                [
                    'name' => $provider['name'],
                    'is_enabled' => $provider['is_enabled'],
                    'is_default' => $provider['is_default'],
                    'config' => $provider['config'],
                ],
            );
        }
    }
}
