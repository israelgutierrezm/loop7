<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sesión de impersonación de SUPERADMIN (docs/09): se guarda quién impersona y
 * desde cuándo; caduca a los 60 minutos y la auditoría registra al
 * administrador que actúa realmente.
 */
final class ImpersonationSession
{
    public const TTL_MINUTES = 60;

    private const IMPERSONATOR = 'impersonator_id';

    private const STARTED_AT = 'impersonation_started_at';

    public static function begin(Request $request, User $admin, User $target): void
    {
        $request->session()->put(self::IMPERSONATOR, $admin->id);
        $request->session()->put(self::STARTED_AT, now()->getTimestamp());

        // login() migra la sesión (nuevo id) conservando estos datos.
        Auth::guard('web')->login($target);
    }

    /**
     * Termina la impersonación y devuelve la sesión al administrador.
     */
    public static function end(Request $request): ?User
    {
        $impersonatorId = self::impersonatorId($request);
        $request->session()->forget([self::IMPERSONATOR, self::STARTED_AT]);

        $admin = $impersonatorId !== null ? User::query()->find($impersonatorId) : null;
        if ($admin !== null) {
            Auth::guard('web')->login($admin);
        }

        return $admin;
    }

    public static function impersonatorId(?Request $request = null): ?int
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::IMPERSONATOR);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function active(?Request $request = null): bool
    {
        return self::impersonatorId($request) !== null;
    }

    public static function expired(Request $request): bool
    {
        $startedAt = (int) $request->session()->get(self::STARTED_AT, 0);

        // Sesiones previas a este control (sin marca de inicio) también caducan.
        return $startedAt === 0 || now()->getTimestamp() - $startedAt > self::TTL_MINUTES * 60;
    }
}
