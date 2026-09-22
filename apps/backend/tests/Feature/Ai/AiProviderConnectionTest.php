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
}
