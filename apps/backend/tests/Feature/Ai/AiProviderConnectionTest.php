<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Modules\Ai\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
    }

    private function setKey(string $provider, string $key): void
    {
        $record = AiProvider::query()->where('key', $provider)->first();
        $record->credentials = ['api_key' => $key];
        $record->save();
    }

    public function test_probar_conexion_fake_es_ok(): void
    {
        $this->postJson('/api/v1/platform/ai-providers/fake/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_probar_conexion_openai_con_key_valida(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]);
        $this->setKey('openai', 'sk-valida');

        $this->postJson('/api/v1/platform/ai-providers/openai/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_probar_conexion_openai_key_invalida(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([], 401)]);
        $this->setKey('openai', 'sk-mala');

        $this->postJson('/api/v1/platform/ai-providers/openai/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false);
    }

    public function test_probar_conexion_anthropic_con_key_valida(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);
        $this->setKey('anthropic', 'sk-ant-valida');

        $this->postJson('/api/v1/platform/ai-providers/anthropic/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_probar_sin_key_pide_configurar(): void
    {
        // openai sin credenciales guardadas.
        $this->postJson('/api/v1/platform/ai-providers/openai/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false);
    }

    public function test_solo_superadmin_puede_probar(): void
    {
        Sanctum::actingAs(User::factory()->create()); // usuario normal

        $this->postJson('/api/v1/platform/ai-providers/fake/test')->assertForbidden();
    }

    public function test_actualiza_la_lista_de_modelos_desde_el_proveedor(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => [
            ['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
            ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5'],
        ]])]);
        $this->setKey('anthropic', 'sk-ant-valida');

        $this->postJson('/api/v1/platform/ai-providers/anthropic/models')
            ->assertOk()
            ->assertJsonPath('data.text_models', ['claude-opus-5', 'claude-sonnet-5'])
            ->assertJsonPath('data.supports_images', false);

        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-ant-valida') && $r->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function test_openai_separa_modelos_de_texto_e_imagen(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [
            ['id' => 'gpt-4o-mini'], ['id' => 'gpt-image-1'], ['id' => 'text-embedding-3-small'],
            ['id' => 'gpt-4o-realtime-preview'], ['id' => 'dall-e-3'], ['id' => 'o3-mini'],
        ]])]);
        $this->setKey('openai', 'sk-valida');

        $this->postJson('/api/v1/platform/ai-providers/openai/models')
            ->assertOk()
            ->assertJsonPath('data.text_models', ['gpt-4o-mini', 'o3-mini'])
            ->assertJsonPath('data.image_models', ['dall-e-3', 'gpt-image-1']);
    }

    public function test_anthropic_genera_texto_con_la_api_vigente(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Post listo para publicar.'],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ])]);

        $result = (new \App\Modules\Ai\Providers\AnthropicProvider())->generateText(
            new \App\Modules\Ai\Contracts\TextGenerationRequest('Escribe un post', 'Marca cercana'),
            ['api_key' => 'sk-ant'],
        );

        $this->assertSame('Post listo para publicar.', $result->text);
        $this->assertSame(120, $result->inputTokens);
        Http::assertSent(function ($r) {
            $body = $r->data();

            return $body['model'] === 'claude-opus-5'
                && ! array_key_exists('temperature', $body)     // los modelos vigentes lo rechazan
                && $body['max_tokens'] >= 16000                 // margen para el razonamiento
                && $body['system'] === 'Marca cercana'
                && $body['fallbacks'] === 'default'
                && $r->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01');
        });
    }

    public function test_anthropic_informa_un_rechazo(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => 'Contenido no permitido.'],
            'content' => [],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Claude declinó generar este contenido.');

        (new \App\Modules\Ai\Providers\AnthropicProvider())->generateText(
            new \App\Modules\Ai\Contracts\TextGenerationRequest('x'),
            ['api_key' => 'sk-ant'],
        );
    }
}
