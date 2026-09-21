<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración de proveedores sociales desde SUPERADMIN. Las credenciales se
 * guardan cifradas y se devuelven sólo como lista de claves configuradas.
 */
class PlatformSocialProvidersController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $providers = SocialProvider::query()->orderBy('name')->get()
            ->map(fn (SocialProvider $p) => $this->present($p))
            ->all();

        return ApiResponse::success($providers);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $record = SocialProvider::query()->where('key', $provider)->firstOrFail();

        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $record->update($data);

        $this->audit->log(AuditAction::PAYMENT_GATEWAY_UPDATED, $record, [
            'social_provider' => $record->key,
            'is_enabled' => $record->is_enabled,
        ]);

        return ApiResponse::success($this->present($record), 'Proveedor actualizado.');
    }

    public function setCredentials(Request $request, string $provider): JsonResponse
    {
        $record = SocialProvider::query()->where('key', $provider)->firstOrFail();

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

        $this->audit->log(AuditAction::PAYMENT_GATEWAY_UPDATED, $record, [
            'social_provider' => $record->key,
            'credentials_updated' => array_keys($data['credentials']),
        ]);

        return ApiResponse::success($this->present($record), 'Credenciales guardadas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SocialProvider $provider): array
    {
        return [
            'key' => $provider->key,
            'name' => $provider->name,
            'is_enabled' => $provider->is_enabled,
            'configured_credentials' => array_keys($provider->credentialMap()),
        ];
    }
}
