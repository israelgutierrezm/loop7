<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración de proveedores de IA desde SUPERADMIN (docs/07): habilitar,
 * marcar por defecto, modelos y credenciales (cifradas y enmascaradas).
 */
class PlatformAiProvidersController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $providers = AiProvider::query()->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (AiProvider $p) => $this->present($p))
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
            'requires_credentials' => $provider->key !== 'fake',
            'configured_credentials' => array_keys($provider->credentialMap()),
        ];
    }
}
