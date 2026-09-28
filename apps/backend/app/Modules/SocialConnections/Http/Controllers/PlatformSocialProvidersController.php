<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\SocialConnections\Contracts\HasApiVersion;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Configuración de proveedores sociales desde SUPERADMIN: habilitar, credenciales
 * de la app (cifradas; sólo se devuelve qué claves hay), versión de la API (Graph
 * de Meta, LinkedIn-Version…), scopes y "Probar conexión". Muestra las URLs a
 * registrar en la app del proveedor.
 */
class PlatformSocialProvidersController extends Controller
{
    /**
     * Cómo llama cada proveedor a las credenciales y dónde se registra la URI
     * de redirección (para guiar a SUPERADMIN).
     *
     * @var array<string, array{client_id: string, client_secret: string, redirect_hint: string}>
     */
    private const SETUP = [
        'facebook' => [
            'client_id' => 'App ID (client_id)',
            'client_secret' => 'App Secret (client_secret)',
            'redirect_hint' => 'Regístrala en Inicio de sesión con Facebook → Configuración.',
        ],
        'instagram' => [
            'client_id' => 'App ID (client_id)',
            'client_secret' => 'App Secret (client_secret)',
            'redirect_hint' => 'Regístrala en Inicio de sesión con Facebook → Configuración.',
        ],
        'threads' => [
            'client_id' => 'ID de la app de Threads',
            'client_secret' => 'Clave secreta de la app de Threads',
            'redirect_hint' => 'Regístrala en tu app de Meta → Casos de uso → Acceder a la API de Threads → Configuración (URL de devolución de llamada).',
        ],
        'linkedin' => [
            'client_id' => 'Client ID',
            'client_secret' => 'Primary Client Secret',
            'redirect_hint' => 'Regístrala en tu app de LinkedIn → Auth → Authorized redirect URLs for your app.',
        ],
        'x' => [
            'client_id' => 'OAuth 2.0 Client ID',
            'client_secret' => 'OAuth 2.0 Client Secret',
            'redirect_hint' => 'Regístrala en el portal de desarrolladores de X → tu app → User authentication settings → Callback URI (tipo de app: Web App, confidencial).',
        ],
        'youtube' => [
            'client_id' => 'ID de cliente de OAuth',
            'client_secret' => 'Secreto del cliente',
            'redirect_hint' => 'Regístrala en Google Cloud → APIs y servicios → Credenciales → cliente OAuth «Aplicación web» → URIs de redireccionamiento autorizados.',
        ],
        'tiktok' => [
            'client_id' => 'Client key',
            'client_secret' => 'Client secret',
            'redirect_hint' => 'Regístrala en TikTok for Developers → tu app → Login Kit → Redirect URI.',
        ],
    ];

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
            // `graph_version` se mantiene por compatibilidad; `api_version` sirve para todos.
            'graph_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'api_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'scopes' => ['sometimes', 'nullable', 'array', 'max:40'],
            // Incluye los scopes con forma de URL (Google: https://www.googleapis.com/auth/…).
            'scopes.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9_.:\/-]+$/'],
        ]);

        if (array_key_exists('is_enabled', $data)) {
            $record->is_enabled = (bool) $data['is_enabled'];
        }

        $config = $record->config ?? [];
        $version = array_key_exists('api_version', $data) ? 'api_version' : (array_key_exists('graph_version', $data) ? 'graph_version' : null);
        if ($version !== null) {
            $setting = $this->versionSetting($record->key);
            $value = $data[$version] ?: null;
            if ($setting === null) {
                throw ValidationException::withMessages([$version => 'Este proveedor no tiene una versión de API configurable.']);
            }
            if ($value !== null && preg_match($setting['pattern'], $value) !== 1) {
                throw ValidationException::withMessages([$version => 'La versión debe tener el formato ' . $setting['example'] . '.']);
            }
            $config[$setting['key']] = $value;
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

    /**
     * @return array{key: string, label: string, default: string, pattern: string, example: string, hint: string}|null
     */
    private function versionSetting(string $provider): ?array
    {
        $adapter = $this->manager->adapter($provider);

        return $adapter instanceof HasApiVersion ? $adapter->apiVersionSetting() : null;
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
        $version = $this->versionSetting($provider->key);

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
            'api_version' => $version !== null ? [
                'label' => $version['label'],
                'value' => $provider->config[$version['key']] ?? null,
                'default' => $version['default'],
                'example' => $version['example'],
                'hint' => $version['hint'],
            ] : null,
            'scopes' => $this->manager->scopes($provider->key),
            'default_scopes' => $this->manager->adapter($provider->key)?->defaultScopes() ?? [],
            'credential_labels' => [
                'client_id' => self::SETUP[$provider->key]['client_id'] ?? 'Client ID',
                'client_secret' => self::SETUP[$provider->key]['client_secret'] ?? 'Client Secret',
            ],
            'setup' => [
                'redirect_uri' => $this->connections->redirectUri($provider->key),
                'redirect_hint' => self::SETUP[$provider->key]['redirect_hint'] ?? 'Regístrala como URI de redirección OAuth en la app del proveedor.',
                'data_deletion_url' => $isMeta ? url('/api/v1/data-deletion/facebook') : null,
                'privacy_url' => $frontend . '/privacidad',
                'terms_url' => $frontend . '/terminos',
            ],
        ];
    }
}
