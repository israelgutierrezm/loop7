<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Services\AiProviderManager;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Configuración de proveedores de IA desde SUPERADMIN (docs/07): habilitar,
 * marcar por defecto, modelos, credenciales (cifradas y enmascaradas) y prueba
 * de conexión.
 */
class PlatformAiProvidersController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AiProviderManager $manager,
    ) {
    }

    /**
     * Prueba de conexión: verifica las credenciales guardadas contra el proveedor.
     */
    public function test(string $provider): JsonResponse
    {
        $record = AiProvider::query()->where('key', $provider)->firstOrFail();
        $adapter = $this->manager->textAdapter($provider);

        if ($adapter === null) {
            return ApiResponse::success(['ok' => false, 'message' => 'Este proveedor no tiene adaptador disponible.']);
        }
        if ($record->credentialMap() === [] && $provider !== 'fake') {
            return ApiResponse::success(['ok' => false, 'message' => 'Configura y guarda la API key antes de probar.']);
        }

        try {
            $adapter->verify($record->credentialMap());
        } catch (Throwable $e) {
            return ApiResponse::success(['ok' => false, 'message' => $e->getMessage()]);
        }

        return ApiResponse::success(['ok' => true, 'message' => 'Conexión correcta.']);
    }

    /**
     * Actualiza desde el proveedor la lista de modelos disponibles para la cuenta
     * (así el selector no depende de una lista fija que envejece).
     */
    public function refreshModels(string $provider): JsonResponse
    {
        $record = AiProvider::query()->where('key', $provider)->firstOrFail();
        $adapter = $this->manager->textAdapter($provider);
        abort_if($adapter === null, 404);

        try {
            $models = $adapter->listModels($record->credentialMap());
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 'ai_provider_error', status: 422);
        }

        $config = $record->config ?? [];
        $config['text_models'] = $models['text'];
        if ($this->manager->imageAdapter($provider) !== null) {
            $config['image_models'] = $models['image'];
        }
        $record->config = $config;
        $record->save();

        return ApiResponse::success($this->present($record), 'Modelos actualizados: ' . count($models['text']) . ' de texto.');
    }

    public function index(): JsonResponse
    {
        $providers = AiProvider::query()->orderByDesc('is_default')->orderBy('name')->get()
            ->filter(fn (AiProvider $p) => $this->manager->textAdapter($p->key) !== null || $this->manager->imageAdapter($p->key) !== null)
            ->map(fn (AiProvider $p) => $this->present($p))
            ->values()
            ->all();

        return ApiResponse::success($providers);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $record = AiProvider::query()->where('key', $provider)->firstOrFail();

        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
            'config.text_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'config.image_model' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (array_key_exists('config', $data)) {
            $record->config = array_merge($record->config ?? [], $data['config']);
        }
        if (array_key_exists('is_enabled', $data)) {
            $record->is_enabled = $data['is_enabled'];
        }
        if (array_key_exists('is_default', $data) && $data['is_default']) {
            // Sólo un proveedor por defecto a la vez.
            AiProvider::query()->where('id', '!=', $record->id)->update(['is_default' => false]);
            $record->is_default = true;
        } elseif (array_key_exists('is_default', $data)) {
            $record->is_default = false;
        }
        $record->save();

        $this->audit->log(AuditAction::AI_PROVIDER_UPDATED, $record, [
            'ai_provider' => $record->key,
            'is_enabled' => $record->is_enabled,
            'is_default' => $record->is_default,
        ]);

        return ApiResponse::success($this->present($record), 'Proveedor actualizado.');
    }

    public function setCredentials(Request $request, string $provider): JsonResponse
    {
        $record = AiProvider::query()->where('key', $provider)->firstOrFail();

        $data = $request->validate([
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string'],
        ]);

        $current = $record->credentialMap();
        foreach ($data['credentials'] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $current[$key] = $value;
        }
        $record->update(['credentials' => $current]);

        $this->audit->log(AuditAction::AI_PROVIDER_UPDATED, $record, [
            'ai_provider' => $record->key,
            'credentials_updated' => array_keys($data['credentials']),
        ]);

        return ApiResponse::success($this->present($record), 'Credenciales guardadas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiProvider $provider): array
    {
        $config = $provider->config ?? [];

        return [
            'key' => $provider->key,
            'name' => $provider->name,
            'is_enabled' => $provider->is_enabled,
            'is_default' => $provider->is_default,
            'text_model' => $config['text_model'] ?? null,
            'image_model' => $config['image_model'] ?? null,
            'text_models' => $config['text_models'] ?? [],
            'image_models' => $config['image_models'] ?? [],
            'supports_images' => $this->manager->imageAdapter($provider->key) !== null,
            'requires_credentials' => $provider->key !== 'fake',
            'configured_credentials' => array_keys($provider->credentialMap()),
        ];
    }
}
