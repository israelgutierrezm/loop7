<?php

declare(strict_types=1);

namespace App\Modules\Automations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Services\AutomationEngine;
use App\Modules\Automations\Support\PayloadFlattener;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Webhook entrante de una automatización (docs/05): URL pública cuyo token
 * secreto identifica la regla. Acepta JSON o formulario (≤ 64 KB), responde
 * 202 y ejecuta la regla en la cola. Idempotente con `Idempotency-Key` o
 * `webhook-id` (24 h).
 */
class InboundWebhookController extends Controller
{
    private const MAX_BYTES = 65536;

    public function __invoke(
        Request $request,
        string $token,
        AutomationEngine $engine,
        EntitlementsService $entitlements,
    ): JsonResponse {
        $automation = preg_match('/^[A-Za-z0-9]{40,64}$/', $token) === 1
            ? Automation::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('inbound_token_hash', hash('sha256', $token))
                ->where('trigger', AutomationTrigger::WEBHOOK_RECEIVED->value)
                ->first()
            : null;
        if ($automation === null) {
            return ApiResponse::error('Webhook no encontrado.', 'not_found', status: 404);
        }
        if (! $automation->is_enabled) {
            return ApiResponse::error('La automatización está pausada.', 'automation_paused', status: 409);
        }

        $organization = Organization::query()->find($automation->organization_id);
        if ($organization === null || ! $entitlements->allows($organization, Entitlement::FEATURE_AUTOMATIONS)) {
            return ApiResponse::error('El plan de la organización no incluye automatizaciones.', 'plan_limit', status: 402);
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return ApiResponse::error('El cuerpo supera los 64 KB.', 'payload_too_large', status: 413);
        }

        $key = $request->header('Idempotency-Key') ?: $request->header('webhook-id');
        if (is_string($key) && $key !== ''
            && ! Cache::add('automation-inbound:' . $automation->id . ':' . sha1($key), true, now()->addDay())
        ) {
            return ApiResponse::message('Ya se había recibido este mensaje.');
        }

        $payload = $request->isJson() ? $request->json()->all() : $request->request->all();
        $engine->dispatchDirect($automation, PayloadFlattener::flatten([...$request->query(), ...$payload]));

        return ApiResponse::message('Recibido.', 202);
    }
}
