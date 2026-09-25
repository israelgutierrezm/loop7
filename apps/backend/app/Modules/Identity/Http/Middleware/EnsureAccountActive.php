<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta bloqueada por SUPERADMIN pierde la sesión activa en su siguiente
 * petición (el login ya la rechaza). Funciona con cualquier driver de sesión.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isBlocked()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return ApiResponse::error('Tu cuenta está bloqueada. Escribe a soporte para más información.', 'account_blocked', status: 403);
        }

        return $next($request);
    }
}
