<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Services;

use App\Models\User;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Support\Security\ImpersonationSession;
use Illuminate\Http\Request;

/**
 * Control Enterprise (feature.require_two_factor): la organización exige la
 * verificación en dos pasos a todos sus miembros. Quien no la tenga sólo puede
 * ir a Mi perfil a activarla. Quedan fuera las sesiones abiertas por el SSO de
 * esa organización (el doble factor lo aplica su IdP) y las impersonaciones de
 * soporte.
 */
final class TwoFactorRequirement
{
    /** Clave de sesión que deja el canje del SSO (docs/03). */
    public const SSO_SESSION_KEY = 'sso_organization_id';

    public function __construct(private readonly EntitlementsService $entitlements)
    {
    }

    public function available(Organization $organization): bool
    {
        return $this->entitlements->allows($organization, Entitlement::FEATURE_REQUIRE_TWO_FACTOR);
    }

    /** ¿La organización lo exige hoy? (sin el plan, la opción guardada no rige). */
    public function enforced(Organization $organization): bool
    {
        return $organization->require_two_factor && $this->available($organization);
    }

    public function blocks(User $user, Organization $organization, Request $request): bool
    {
        if (! $this->enforced($organization) || $user->hasTwoFactorEnabled() || ImpersonationSession::active($request)) {
            return false;
        }

        return ! ($request->hasSession() && $request->session()->get(self::SSO_SESSION_KEY) === $organization->id);
    }

    /** Miembros activos que aún no tienen la verificación en dos pasos. */
    public function pendingMembers(Organization $organization): int
    {
        return $organization->users()
            ->wherePivot('status', 'active')
            ->where(fn ($q) => $q->whereNull('two_factor_secret')->orWhereNull('two_factor_confirmed_at'))
            ->count();
    }
}
