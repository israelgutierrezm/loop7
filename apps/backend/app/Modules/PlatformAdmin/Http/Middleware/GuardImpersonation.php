<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Middleware;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiResponse;
use App\Support\Security\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Límites de la impersonación (docs/09): caduca a los 60 minutos y bloquea las
 * acciones que un administrador nunca debe hacer en nombre de otra persona
 * (credenciales, MFA, pagos, claves y borrado de la organización).
 */
class GuardImpersonation
{
    /** @var list<array{0: string, 1: string}> método y patrón de ruta */
    private const BLOCKED = [
        ['PUT', 'api/v1/me/password'],
        ['POST', 'api/v1/me/two-factor/*'],
        ['DELETE', 'api/v1/me/two-factor'],
        ['DELETE', 'api/v1/organization'],
        ['POST', 'api/v1/billing/*'],
        ['POST', 'api/v1/api-keys'],
        ['DELETE', 'api/v1/api-keys/*'],
        ['POST', 'api/v1/ai/keys'],
        ['DELETE', 'api/v1/ai/keys/*'],
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! ImpersonationSession::active($request)) {
            return $next($request);
        }

        if (ImpersonationSession::expired($request)) {
            $admin = ImpersonationSession::end($request);
            $this->audit->log(AuditAction::SUPERADMIN_IMPERSONATION_ENDED, properties: ['reason' => 'timeout'], actor: $admin);

            return ApiResponse::error(
                'La impersonación caducó: vuelves a tu cuenta de administrador.',
                'impersonation_expired',
                status: 401,
            );
        }

        foreach (self::BLOCKED as [$method, $pattern]) {
            if ($request->isMethod($method) && $request->is($pattern)) {
                return ApiResponse::error(
                    'Esta acción no está disponible mientras impersonas a un usuario.',
                    'impersonation_blocked',
                    status: 403,
                );
            }
        }

        return $next($request);
    }
}
