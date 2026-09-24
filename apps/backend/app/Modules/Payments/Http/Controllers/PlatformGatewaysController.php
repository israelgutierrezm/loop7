<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentGatewayCredential;
use App\Modules\Payments\Services\GatewayManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Gestión de pasarelas desde SUPERADMIN. Las credenciales se guardan cifradas
 * y se devuelven siempre enmascaradas (docs/08 configuración segura).
 */
class PlatformGatewaysController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GatewayManager $manager,
    ) {
    }

    /**
     * Prueba de conexión: verifica las credenciales del entorno activo contra la
     * pasarela real. No expone el secreto.
     */
    public function test(string $gateway): JsonResponse
    {
        $record = PaymentGateway::query()->with('credentials')->where('key', $gateway)->firstOrFail();
        $adapter = $this->manager->adapter($gateway);

        if ($adapter === null) {
            return ApiResponse::success(['ok' => false, 'message' => 'Pasarela no disponible.']);
        }

        try {
            $adapter->verifyCredentials($this->manager->credentials($record));
        } catch (Throwable $e) {
            return ApiResponse::success([
                'ok' => false, 'environment' => $record->environment, 'message' => $e->getMessage(),
            ]);
        }

        return ApiResponse::success([
            'ok' => true, 'environment' => $record->environment, 'message' => 'Conexión correcta.',
        ]);
    }

    public function index(): JsonResponse
    {
        $gateways = PaymentGateway::query()->with('credentials')->orderBy('name')->get()
            ->map(fn (PaymentGateway $g) => $this->present($g))
            ->all();

        return ApiResponse::success($gateways);
    }

    public function update(Request $request, string $gateway): JsonResponse
    {
        $record = PaymentGateway::query()->where('key', $gateway)->firstOrFail();

        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'environment' => ['sometimes', Rule::in(['test', 'production'])],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'country' => ['sometimes', Rule::in(['mx', 'co', 'pe'])],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $record->fill(array_intersect_key($data, array_flip(['is_enabled', 'environment'])));
        $config = $record->config ?? [];
        foreach (['currency', 'country', 'instructions'] as $setting) {
            if (array_key_exists($setting, $data)) {
                $config[$setting] = $setting === 'currency' ? mb_strtoupper((string) $data[$setting]) : $data[$setting];
            }
        }
        $record->config = $config;
        $record->save();

        $this->audit->log(AuditAction::PAYMENT_GATEWAY_UPDATED, $record, [
            'gateway' => $record->key,
            'is_enabled' => $record->is_enabled,
            'environment' => $record->environment,
            'changes' => array_keys($data),
        ]);

        return ApiResponse::success($this->present($record->load('credentials')), 'Pasarela actualizada.');
    }

    public function setCredentials(Request $request, string $gateway): JsonResponse
    {
        $record = PaymentGateway::query()->where('key', $gateway)->firstOrFail();

        $data = $request->validate([
            'environment' => ['required', Rule::in(['test', 'production'])],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string'],
        ]);

        foreach ($data['credentials'] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            PaymentGatewayCredential::query()->updateOrCreate(
                ['payment_gateway_id' => $record->id, 'environment' => $data['environment'], 'key' => $key],
                ['value' => $value],
            );
        }

        // El cambio de credenciales es sensible: se audita SIN los valores.
        $this->audit->log(AuditAction::PAYMENT_GATEWAY_UPDATED, $record, [
            'gateway' => $record->key,
            'environment' => $data['environment'],
            'credentials_updated' => array_keys($data['credentials']),
        ]);

        return ApiResponse::success($this->present($record->load('credentials')), 'Credenciales guardadas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentGateway $gateway): array
    {
        return [
            'key' => $gateway->key,
            'name' => $gateway->name,
            'is_enabled' => $gateway->is_enabled,
            'environment' => $gateway->environment,
            'currency' => $this->manager->currency($gateway),
            'country' => $gateway->config['country'] ?? null,
            'instructions' => $gateway->config['instructions'] ?? null,
            'is_offline' => $gateway->key === 'manual',
            'webhook_url' => $gateway->key === 'manual' ? null : url('/api/v1/webhooks/payments/' . $gateway->key),
            'credentials' => $gateway->credentials
                ->map(fn (PaymentGatewayCredential $c) => [
                    'key' => $c->key,
                    'environment' => $c->environment,
                    'masked' => $c->maskedValue(),
                ])
                ->values()
                ->all(),
        ];
    }
}
