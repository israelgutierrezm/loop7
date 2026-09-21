<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentGatewayCredential;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión de pasarelas desde SUPERADMIN. Las credenciales se guardan cifradas
 * y se devuelven siempre enmascaradas (docs/08 configuración segura).
 */
class PlatformGatewaysController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
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
        ]);

        $record->fill($data)->save();

        $this->audit->log(AuditAction::PAYMENT_GATEWAY_UPDATED, $record, [
            'gateway' => $record->key,
            'is_enabled' => $record->is_enabled,
            'environment' => $record->environment,
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
