<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Verifica el correo desde el enlace firmado del email y redirige al SPA.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        if (! $request->hasValidSignature()) {
            return redirect()->away($frontend . '/verificar-correo?status=invalid');
        }

        $user = User::find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($frontend . '/verificar-correo?status=invalid');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $this->audit->log(AuditAction::AUTH_EMAIL_VERIFIED, $user, actor: $user);
        }

        return redirect()->away($frontend . '/verificar-correo?status=success');
    }

    /**
     * Reenvía el correo de verificación al usuario autenticado.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::message('Tu correo ya está verificado.');
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::message('Te enviamos un nuevo correo de verificación.');
    }
}
