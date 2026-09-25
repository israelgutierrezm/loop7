<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use App\Support\Security\ImpersonationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Impersonación auditada por SUPERADMIN. No revela secretos, caduca a los 60
 * minutos (GuardImpersonation) y puede finalizarse en cualquier momento. La
 * impersonación de otros administradores está vetada.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function start(Request $request, string $user): JsonResponse
    {
        $admin = $request->user();
        $target = User::query()->where('public_id', $user)->firstOrFail();

        if ($target->isPlatformAdmin()) {
            return ApiResponse::error('No se puede impersonar a otro administrador de plataforma.', 'forbidden', status: 403);
        }
        if ($target->isBlocked()) {
            return ApiResponse::error('La cuenta está bloqueada: desbloquéala antes de impersonarla.', 'account_blocked', status: 422);
        }

        ImpersonationSession::begin($request, $admin, $target);

        $this->audit->log(AuditAction::SUPERADMIN_IMPERSONATION_STARTED, $target, [
            'impersonator' => $admin->public_id,
            'expires_in_minutes' => ImpersonationSession::TTL_MINUTES,
        ], actor: $admin);

        return ApiResponse::success(new UserResource($target), 'Estás impersonando a este usuario.');
    }

    public function stop(Request $request): JsonResponse
    {
        if (! ImpersonationSession::active($request)) {
            return ApiResponse::error('No hay una sesión de impersonación activa.', 'no_impersonation', status: 409);
        }

        $admin = ImpersonationSession::end($request);
        abort_if($admin === null, 409, 'El administrador que inició la impersonación ya no existe.');

        $this->audit->log(AuditAction::SUPERADMIN_IMPERSONATION_ENDED, actor: $admin);

        return ApiResponse::success(new UserResource($admin), 'Impersonación finalizada.');
    }
}
