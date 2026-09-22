<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Api\Models\ApiKey;
use App\Modules\Api\Services\ApiKeyService;
use App\Modules\Api\Support\ApiScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Gestión de API keys desde el panel (sesión Sanctum). Requiere permiso
 * api.manage y el feature de plan feature.api. El secreto se muestra una vez.
 */
class ApiKeysController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly EntitlementsService $entitlements,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureEnabled($request);

        $items = ApiKey::query()->latest()->get()->map(fn (ApiKey $k) => $this->present($k))->all();

        return ApiResponse::success([
            'keys' => $items,
            'scopes' => ApiScope::catalog(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:' . implode(',', ApiScope::all())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $organization = $this->tenant->organization();
        $result = $this->keys->generate(
            $organization,
            $data['name'],
            $data['scopes'],
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $request->user(),
        );

        $this->audit->log(AuditAction::API_KEY_CREATED, $result['model'], ['name' => $data['name'], 'scopes' => $data['scopes']]);

        return ApiResponse::success([
            ...$this->present($result['model']),
            'key' => $result['plain'], // única vez
        ], 'API key creada. Copia el valor: no volverá a mostrarse.', status: 201);
    }

    public function destroy(Request $request, string $apiKey): JsonResponse
    {
        $this->ensureEnabled($request);

        $model = ApiKey::query()->where('public_id', $apiKey)->firstOrFail();
        $model->delete();

        $this->audit->log(AuditAction::API_KEY_REVOKED, null, ['name' => $model->name]);

        return ApiResponse::message('API key revocada.');
    }

    private function ensureEnabled(Request $request): void
    {
        abort_unless($request->user()->can('api.manage'), 403);

        $organization = $this->tenant->organization();
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_API)) {
            throw new PlanLimitExceededException('Tu plan no incluye acceso a la API pública.', Entitlement::FEATURE_API);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApiKey $k): array
    {
        return [
            'id' => $k->public_id,
            'name' => $k->name,
            'prefix' => $k->prefix,
            'scopes' => $k->scopes,
            'is_active' => $k->is_active,
            'last_used_at' => $k->last_used_at?->toIso8601String(),
            'expires_at' => $k->expires_at?->toIso8601String(),
            'created_at' => $k->created_at?->toIso8601String(),
        ];
    }
}
