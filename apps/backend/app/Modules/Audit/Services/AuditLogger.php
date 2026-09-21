<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Punto único para registrar auditoría. Nunca almacena secretos.
 */
class AuditLogger
{
    /**
     * Claves que jamás deben persistirse en properties.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token', 'token_hash',
        'secret', 'client_secret', 'access_token', 'refresh_token', 'api_key', 'apikey',
        'two_factor_secret', 'two_factor_recovery_codes', 'card', 'cvv', 'authorization',
    ];

    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        array $properties = [],
        ?string $description = null,
        ?User $actor = null,
        ?int $organizationId = null,
    ): AuditLog {
        $request = request();
        $actor ??= Auth::user();

        return AuditLog::create([
            'organization_id' => $organizationId ?? $this->tenant->organizationId(),
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description,
            'properties' => $this->sanitize($properties),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitize(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (in_array(mb_strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $properties[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $properties[$key] = $this->sanitize($value);
            }
        }

        return $properties;
    }
}
