<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\PlatformAdmin\Services\PlatformSettings;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SUPERADMIN → Configuración: datos de la empresa (páginas legales), registro,
 * reglas de billing y aviso del sistema. `publicConfig` expone sin sesión sólo
 * lo necesario para las páginas públicas y el banner.
 */
class PlatformSettingsController extends Controller
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->settings->all());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company.name' => ['sometimes', 'string', 'max:120'],
            'company.legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'company.tax_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'company.contact_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'company.support_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'company.country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'company.address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'registration.open' => ['sometimes', 'boolean'],
            'billing.trial_plan' => ['sometimes', 'string', Rule::exists('plans', 'key')],
            'billing.trial_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'billing.grace_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'announcement.enabled' => ['sometimes', 'boolean'],
            'announcement.message' => ['sometimes', 'nullable', 'string', 'max:300'],
            'announcement.tone' => ['sometimes', Rule::in(['info', 'warning'])],
        ]);

        // El validador anida las claves con punto: se aplanan de nuevo.
        $flat = [];
        foreach (array_keys(PlatformSettings::DEFAULTS) as $key) {
            if (data_get($data, $key, '__missing__') !== '__missing__') {
                $flat[$key] = data_get($data, $key) ?? '';
            }
        }

        $this->settings->set($flat);
        $this->audit->log(AuditAction::PLATFORM_SETTINGS_UPDATED, null, ['keys' => array_keys($flat)]);

        return ApiResponse::success($this->settings->all(), 'Configuración guardada.');
    }

    /**
     * Planes activos para elegir el plan de prueba.
     */
    public function trialPlans(): JsonResponse
    {
        return ApiResponse::success(
            Plan::query()->where('is_active', true)->orderBy('sort_order')->get(['key', 'name'])->toArray(),
        );
    }

    /**
     * Configuración pública (sin autenticación).
     */
    public function publicConfig(): JsonResponse
    {
        return ApiResponse::success($this->settings->publicConfig());
    }
}
