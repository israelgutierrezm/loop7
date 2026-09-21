<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Models\OrganizationAiKey;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión de claves propias de IA (BYOK) de la Organization. Requiere el permiso
 * ai.manage_own_keys y el feature de plan feature.byok. Las claves se guardan
 * cifradas y nunca se devuelven al navegador (docs/07).
 */
class AiKeysController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementsService $entitlements,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('ai.manage_own_keys'), 403);
        $organization = $this->tenant->organization();
        abort_if($organization === null, 400, 'Sin organización en contexto.');

        $keys = OrganizationAiKey::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(fn (OrganizationAiKey $k) => [
                'provider' => $k->provider,
                'is_active' => $k->is_active,
                'configured_credentials' => array_keys($k->credentialMap()),
            ])
            ->all();

        return ApiResponse::success([
            'available' => $this->entitlements->allows($organization, Entitlement::FEATURE_BYOK),
            'providers' => AiProvider::query()->orderBy('name')->get()
                ->map(fn (AiProvider $p) => ['key' => $p->key, 'name' => $p->name])
                ->all(),
            'keys' => $keys,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('ai.manage_own_keys'), 403);
        $organization = $this->tenant->organization();
        abort_if($organization === null, 400, 'Sin organización en contexto.');

        if (! $this->entitlements->allows($organization, Entitlement::FEATURE_BYOK)) {
            throw new PlanLimitExceededException(
                'Tu plan no permite usar claves propias de IA (BYOK).',
                Entitlement::FEATURE_BYOK,
            );
        }

        $data = $request->validate([
            'provider' => ['required', 'string', Rule::exists('ai_providers', 'key')],
            'credentials' => ['required', 'array'],
            'credentials.api_key' => ['required', 'string', 'max:500'],
        ]);

        $key = OrganizationAiKey::query()->firstOrNew([
            'organization_id' => $organization->id,
            'provider' => $data['provider'],
        ]);
        $key->credentials = $data['credentials'];
        $key->is_active = true;
        $key->save();

        $this->audit->log(AuditAction::AI_KEY_UPDATED, $key, [
            'provider' => $data['provider'],
        ], organizationId: $organization->id);

        return ApiResponse::success([
            'provider' => $key->provider,
            'is_active' => $key->is_active,
            'configured_credentials' => array_keys($key->credentialMap()),
        ], 'Clave guardada de forma cifrada.');
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        abort_unless($request->user()->can('ai.manage_own_keys'), 403);
        $organization = $this->tenant->organization();
        abort_if($organization === null, 400, 'Sin organización en contexto.');

        $key = OrganizationAiKey::query()
            ->where('organization_id', $organization->id)
            ->where('provider', $provider)
            ->firstOrFail();
        $key->delete();

        $this->audit->log(AuditAction::AI_KEY_REMOVED, null, [
            'provider' => $provider,
        ], organizationId: $organization->id);

        return ApiResponse::message('Clave eliminada.');
    }
}
