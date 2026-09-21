<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Impersonación auditada por SUPERADMIN. No revela secretos y puede finalizarse
 * en cualquier momento. La impersonación de otros administradores está vetada.
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

        $request->session()->put('impersonator_id', $admin->id);
        Auth::guard('web')->login($target);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->audit->log(AuditAction::SUPERADMIN_IMPERSONATION_STARTED, $target, [
            'impersonator' => $admin->public_id,
        ], actor: $admin);

        return ApiResponse::success(new UserResource($target), 'Estás impersonando a este usuario.');
    }

    public function stop(Request $request): JsonResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');

        if ($impersonatorId === null) {
            return ApiResponse::error('No hay una sesión de impersonación activa.', 'no_impersonation', status: 409);
        }

        $admin = User::query()->findOrFail($impersonatorId);
        Auth::guard('web')->login($admin);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->audit->log(AuditAction::SUPERADMIN_IMPERSONATION_ENDED, actor: $admin);

        return ApiResponse::success(new UserResource($admin), 'Impersonación finalizada.');
    }
}
