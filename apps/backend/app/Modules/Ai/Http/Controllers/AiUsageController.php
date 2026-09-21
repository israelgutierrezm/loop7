<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Models\AiUsageLog;
use App\Modules\Ai\Services\AiCreditService;
use App\Modules\Ai\Services\AiProviderManager;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel de uso de IA de la Organization actual: créditos del periodo, si el plan
 * permite BYOK, proveedores disponibles y registro reciente (docs/07).
 */
class AiUsageController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AiCreditService $credits,
        private readonly AiProviderManager $manager,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('ai.view_usage'), 403);
        $organization = $this->tenant->organization();
        abort_if($organization === null, 400, 'Sin organización en contexto.');

        $recent = AiUsageLog::query()
            ->with('brand:id,public_id,name')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AiUsageLog $log) => [
                'id' => $log->public_id,
                'provider' => $log->provider,
                'model' => $log->model,
                'modality' => $log->modality,
                'operation' => $log->operation,
                'units' => $log->units,
                'credits' => $log->credits,
                'status' => $log->status,
                'byok' => $log->byok,
                'brand' => $log->brand?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        return ApiResponse::success([
            'period' => $this->credits->period(),
            'limit' => $this->credits->limit($organization),
            'used' => $this->credits->used($organization),
            'remaining' => $this->credits->remaining($organization),
            'unlimited' => $this->credits->isUnlimited($organization),
            'byok_available' => $this->entitlements->allows($organization, Entitlement::FEATURE_BYOK),
            'providers' => $this->manager->enabled()
                ->map(fn (AiProvider $p) => ['key' => $p->key, 'name' => $p->name])
                ->all(),
            'recent' => $recent,
        ]);
    }
}
