<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Exceptions\SsoException;
use App\Modules\Sso\Http\Requests\AcsRequest;
use App\Modules\Sso\Http\Requests\DiscoverSsoRequest;
use App\Modules\Sso\Http\Requests\ExchangeSsoCodeRequest;
use App\Modules\Sso\Services\SamlSettings;
use App\Modules\Sso\Services\SsoService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Inicio de sesión único (público): descubrir la organización por el correo,
 * metadatos del SP, ACS y canje del código por la sesión del SPA (docs/03).
 */
class SsoLoginController extends Controller
{
    public function __construct(
        private readonly SsoService $sso,
        private readonly AuditLogger $audit,
    ) {
    }

    public function discover(DiscoverSsoRequest $request): JsonResponse
    {
        $connection = $this->sso->forEmail($request->email());
        $organization = $connection !== null ? Organization::query()->find($connection->organization_id) : null;
        if ($connection === null || $organization === null) {
            return ApiResponse::error(
                'Tu correo no tiene inicio de sesión único configurado. Entra con tu contraseña.',
                'sso_not_available',
                status: 404,
            );
        }

        return ApiResponse::success([
            'redirect_url' => $this->sso->start($organization, $connection, $request->challenge()),
        ]);
    }

    /**
     * Metadatos SAML del SP para configurar el IdP (Entity ID = esta URL).
     */
    public function metadata(string $organization, EntitlementsService $entitlements, SamlSettings $settings): Response
    {
        $model = Organization::query()->where('public_id', $organization)->first();
        abort_if($model === null || ! $entitlements->allows($model, Entitlement::FEATURE_SSO), 404);

        return response($settings->metadata($model), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Assertion Consumer Service: el IdP envía aquí la respuesta (POST desde el navegador).
     */
    public function acs(AcsRequest $request, string $organization): RedirectResponse
    {
        $model = Organization::query()->where('public_id', $organization)->first();
        if ($model === null) {
            return redirect()->away(rtrim((string) config('app.frontend_url'), '/') . '/login?sso_error=' . SsoException::NOT_ENABLED, 303);
        }

        return redirect()->away($this->sso->consume($model, $request->samlResponse(), $request->relayState()), 303);
    }

    public function exchange(ExchangeSsoCodeRequest $request): JsonResponse
    {
        try {
            [$user, $organization] = $this->sso->exchange($request->code(), $request->verifier());
        } catch (SsoException $e) {
            return ApiResponse::error(
                'El inicio de sesión con SSO caducó o no es válido. Vuelve a intentarlo.',
                'sso_failed',
                ['reason' => $e->reason],
                status: 422,
            );
        }

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->log(AuditAction::SSO_LOGIN, $user, actor: $user, organizationId: $organization->id);

        return ApiResponse::success([
            'user' => (new UserResource($user))->resolve($request),
            'organization' => $organization->public_id,
        ], 'Sesión iniciada con SSO.');
    }
}
