<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe el acceso al panel de plataforma (SUPERADMIN).
 *
 * SUPERADMIN vive fuera del RBAC de cliente: se identifica por
 * users.is_platform_admin (docs/18 #8).
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('No autenticado.', 'unauthenticated', status: 401);
        }

        if (! $user->isPlatformAdmin()) {
            return ApiResponse::error('Acceso restringido a administradores de plataforma.', 'forbidden', status: 403);
        }

        // Refuerzo opcional: exigir MFA a SUPERADMIN (checklist preprod).
        if (config('platform.require_mfa_for_superadmin', false) && ! $user->hasTwoFactorEnabled()) {
            return ApiResponse::error('El administrador de plataforma debe activar MFA.', 'mfa_required', status: 403);
        }

        return $next($request);
    }
}
