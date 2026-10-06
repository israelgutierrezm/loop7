<?php

declare(strict_types=1);

namespace App\Modules\Sso\Services;

use App\Models\User;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;

/**
 * SSO obligatorio: quien es miembro activo de una organización con SSO activo y
 * obligatorio, y tiene un correo de uno de sus dominios verificados, ya no entra
 * con contraseña. La persona propietaria la conserva como acceso de emergencia
 * (si el IdP falla, alguien puede entrar a desactivarlo).
 */
final class SsoEnforcement
{
    public function __construct(
        private readonly SsoDomains $domains,
        private readonly SsoService $sso,
    ) {
    }

    /**
     * Organización que le obliga a entrar por SSO, o null si puede usar su contraseña.
     */
    public function requiredBy(User $user): ?Organization
    {
        if ($user->isPlatformAdmin()) {
            return null;
        }

        $domain = $this->domains->ownerOf($user->email);
        $organization = $domain !== null ? Organization::query()->find($domain->organization_id) : null;
        if ($organization === null || $organization->owner_user_id === $user->id) {
            return null;
        }

        $connection = $this->sso->connectionOf($organization);
        if ($connection === null || ! $connection->enforced || ! $this->sso->usable($organization, $connection)) {
            return null;
        }

        $member = $organization->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', MembershipStatus::ACTIVE->value)
            ->exists();

        return $member ? $organization : null;
    }
}
