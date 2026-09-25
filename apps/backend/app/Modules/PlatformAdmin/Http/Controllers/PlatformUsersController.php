<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Usuarios de la plataforma (SUPERADMIN): búsqueda, bloqueo por abuso y
 * restablecimiento del doble factor para quien perdió su dispositivo.
 * Otros administradores de plataforma no se bloquean ni se modifican aquí.
 */
class PlatformUsersController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->withCount('organizations')->latest()->latest('id');

        if ($request->filled('q')) {
            $term = '%' . addcslashes($request->string('q')->trim()->toString(), '%_\\') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        if ($request->boolean('blocked')) {
            $query->whereNotNull('blocked_at');
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));
        $users = $query->paginate($perPage)->through(fn (User $u) => $this->present($u));

        return ApiResponse::paginated($users);
    }

    public function block(Request $request, string $user): JsonResponse
    {
        $model = $this->resolveManageable($request, $user);

        if (! $model->isBlocked()) {
            $model->forceFill(['blocked_at' => now()])->save();
            $model->tokens()->delete(); // tokens de API personales, si los hubiera
            $this->audit->log(AuditAction::SUPERADMIN_USER_BLOCKED, $model, ['email' => $model->email]);
        }

        return ApiResponse::success($this->present($model->loadCount('organizations')), 'Cuenta bloqueada: perderá la sesión en su siguiente acción.');
    }

    public function unblock(Request $request, string $user): JsonResponse
    {
        $model = $this->resolveManageable($request, $user);

        if ($model->isBlocked()) {
            $model->forceFill(['blocked_at' => null])->save();
            $this->audit->log(AuditAction::SUPERADMIN_USER_UNBLOCKED, $model, ['email' => $model->email]);
        }

        return ApiResponse::success($this->present($model->loadCount('organizations')), 'Cuenta desbloqueada.');
    }

    public function resetTwoFactor(Request $request, string $user): JsonResponse
    {
        $model = $this->resolveManageable($request, $user);

        $model->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->audit->log(AuditAction::SUPERADMIN_USER_MFA_RESET, $model, ['email' => $model->email]);

        return ApiResponse::success(
            $this->present($model->loadCount('organizations')),
            'Doble factor restablecido: la persona podrá entrar con su contraseña y volver a activarlo.',
        );
    }

    private function resolveManageable(Request $request, string $publicId): User
    {
        $model = User::query()->where('public_id', $publicId)->firstOrFail();

        abort_if($model->id === $request->user()->id, 422, 'No puedes aplicarte esta acción a ti mismo.');
        abort_if($model->isPlatformAdmin(), 403, 'Los administradores de plataforma no se gestionan desde aquí.');

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $u): array
    {
        return [
            'id' => $u->public_id,
            'name' => $u->name,
            'email' => $u->email,
            'is_platform_admin' => (bool) $u->is_platform_admin,
            'two_factor_enabled' => $u->hasTwoFactorEnabled(),
            'email_verified' => $u->email_verified_at !== null,
            'blocked' => $u->isBlocked(),
            'blocked_at' => $u->blocked_at?->toIso8601String(),
            'organizations_count' => $u->organizations_count ?? null,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
