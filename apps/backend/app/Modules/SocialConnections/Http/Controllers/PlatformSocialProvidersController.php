<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Configuración de proveedores sociales desde SUPERADMIN: habilitar, credenciales
 * de la app (cifradas; sólo se devuelve qué claves hay), versión de Graph API,
 * scopes y "Probar conexión". Muestra las URLs a registrar en la app del proveedor.
 */
class PlatformSocialProvidersController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SocialProviderManager $manager,
        private readonly SocialConnectionService $connections,
    ) {
    }

    public function index(): JsonResponse
    {
        $providers = SocialProvider::query()->orderBy('name')->get()
            ->filter(fn (SocialProvider $p) => $this->manager->adapter($p->key) !== null)
            ->map(fn (SocialProvider $p) => $this->present($p))
            ->values()
            ->all();

        return ApiResponse::success($providers);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $record = $this->resolve($provider);

        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'graph_version' => ['sometimes', 'nullable', 'string', 'regex:/^v\d+\.\d+$/'],
            'scopes' => ['sometimes', 'nullable', 'array', 'max:40'],
            'scopes.*' => ['string', 'max:80', 'regex:/^[a-z0-9_.:]+$/'],
        ], [
            'graph_version.regex' => 'La versión debe tener el formato v25.0.',
        ]);

        if (array_key_exists('is_enabled', $data)) {
            $record->is_enabled = (bool) $data['is_enabled'];
        }

        $config = $record->config ?? [];
        if (array_key_exists('graph_version', $data)) {
            $config['graph_version'] = $data['graph_version'] ?: null;
        }
        if (array_key_exists('scopes', $data)) {
            $config['scopes'] = array_values(array_unique($data['scopes'] ?? []));
        }
        $record->config = array_filter($config, fn ($v) => $v !== null && $v !== []);
        $record->save();

        $this->audit->log(AuditAction::SOCIAL_PROVIDER_UPDATED, $record, [
            'social_provider' => $record->key,
            'changes' => array_keys($data),
        ]);

        return ApiResponse::success($this->present($record), 'Proveedor actualizado.');
    }

    public function setCredentials(Request $request, string $provider): JsonResponse
    {
        $record = $this->resolve($provider);

        $data = $request->validate([
            'credentials' => ['required', 'array'],
            'credentials.client_id' => ['nullable', 'string', 'max:255'],
            'credentials.client_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $current = $record->credentialMap();
        foreach (['client_id', 'client_secret'] as $key) {
            $value = $data['credentials'][$key] ?? null;
            if (is_string($value) && $value !== '') {
                $current[$key] = $value;
            }
        }
        $record->update(['credentials' => $current]);

        $this->audit->log(AuditAction::SOCIAL_PROVIDER_UPDATED, $record, [
            'social_provider' => $record->key,
            'credentials_updated' => array_keys(array_filter($data['credentials'])),
        ]);

        return ApiResponse::success($this->present($record), 'Credenciales guardadas.');
    }

    /**
     * Verifica contra el proveedor las credenciales guardadas (propias o compartidas).
     */
    public function test(string $provider): JsonResponse
    {
        $record = $this->resolve($provider);
        $adapter = $this->manager->adapter($record->key);

        try {
            $adapter?->verifyCredentials($this->manager->credentials($record->key));
        } catch (Throwable $e) {
            return ApiResponse::success(['ok' => false, 'message' => $e->getMessage()]);
        }

        return ApiResponse::success(['ok' => true, 'message' => 'Conexión correcta con ' . $record->name . '.']);
    }

    private function resolve(string $provider): SocialProvider
    {
        $record = SocialProvider::query()->where('key', $provider)->firstOrFail();
        abort_if($this->manager->adapter($record->key) === null, 404);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SocialProvider $provider): array
    {
        $sharesWith = $this->manager->sharesAppWith($provider->key);
        $isMeta = in_array($provider->key, ['facebook', 'instagram'], true);
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return [
            'key' => $provider->key,
            'name' => $provider->name,
            'is_enabled' => $provider->is_enabled,
            'requires_app' => $provider->key !== 'fake',
            'configured_credentials' => array_keys($provider->credentialMap()),
            'shares_app_with' => $sharesWith,
            'uses_shared_credentials' => $sharesWith !== null
                && empty($provider->credentialMap()['client_id'])
                && ! empty($this->manager->credentials($provider->key)['client_id']),
            'graph_version' => $isMeta ? ($provider->config['graph_version'] ?? null) : null,
            'default_graph_version' => $isMeta ? (string) config('services.meta.graph_version') : null,
            'scopes' => $this->manager->scopes($provider->key),
            'default_scopes' => $this->manager->adapter($provider->key)?->defaultScopes() ?? [],
            'setup' => [
                'redirect_uri' => $this->connections->redirectUri($provider->key),
                'data_deletion_url' => $isMeta ? url('/api/v1/data-deletion/facebook') : null,
                'privacy_url' => $frontend . '/privacidad',
                'terms_url' => $frontend . '/terminos',
            ],
        ];
    }
}
