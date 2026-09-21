<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Organizations\Actions\CreateOrganizationForUser;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function __construct(
        private readonly CreateOrganizationForUser $createOrganization,
        private readonly AuditLogger $audit,
    ) {
    }

    public function store(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'locale' => 'es',
                'timezone' => 'UTC',
            ]);

            $organizationName = $request->filled('organization_name')
                ? $request->string('organization_name')->toString()
                : $user->name;

            $this->createOrganization->handle($user, $organizationName);

            return $user;
        });

        // Envía el correo de verificación (notificación por defecto de Laravel).
        event(new Registered($user));

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->audit->log(AuditAction::AUTH_REGISTERED, $user, actor: $user);

        return ApiResponse::success(
            new UserResource($user),
            'Cuenta creada. Te enviamos un correo para verificar tu dirección.',
            status: 201,
        );
    }
}
