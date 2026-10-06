<?php

declare(strict_types=1);

namespace App\Modules\Sso\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\AccessControl\Services\RoleCatalog;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Enums\InvitationStatus;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Exceptions\SsoException;
use App\Modules\Sso\Models\SsoConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Persona que entra por SSO: la existente (miembro activo de la organización)
 * o, con el alta automática activada y si el correo aún no tiene cuenta, una
 * nueva cuenta y membresía con el rol por defecto, respetando las plazas del plan.
 */
final class SsoProvisioner
{
    /** Ya es miembro activo. */
    public const MEMBER = 'member';
    /** Entraría con el alta automática. */
    public const PROVISION = 'provision';

    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly PermissionRegistrar $registrar,
        private readonly RoleCatalog $roles,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Qué pasaría con este correo, sin cambiar nada (la prueba de conexión).
     *
     * @throws SsoException
     */
    public function assess(Organization $organization, SsoConnection $connection, string $email): string
    {
        return $this->decide($organization, $connection, $this->existing($email));
    }

    /**
     * @throws SsoException
     */
    public function resolve(Organization $organization, SsoConnection $connection, string $email, ?string $name): User
    {
        $user = $this->existing($email);
        if ($this->decide($organization, $connection, $user) === self::MEMBER && $user !== null) {
            return $user;
        }

        try {
            return DB::transaction(function () use ($organization, $connection, $email, $name): User {
                // El IdP ya autenticó y el dominio está verificado: correo verificado y
                // contraseña inutilizable (entra por SSO o restableciéndola).
                $user = User::query()->create([
                    'name' => $name !== null && trim($name) !== '' ? Str::limit(trim($name), 120, '') : (string) Str::before($email, '@'),
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $organization->users()->attach($user->id, [
                    'status' => MembershipStatus::ACTIVE->value,
                    'all_brands_access' => true,
                    'joined_at' => now(),
                ]);
                // Si el rol por defecto ya no existe (rol personalizado eliminado), el mínimo.
                $role = $connection->default_role !== OrganizationRole::OWNER->value
                    && in_array($connection->default_role, $this->roles->names($organization), true)
                    ? $connection->default_role
                    : OrganizationRole::VIEWER->value;
                $this->registrar->setPermissionsTeamId($organization->id);
                $user->unsetRelation('roles');
                $user->syncRoles([$role]);

                $this->audit->log(
                    AuditAction::SSO_USER_PROVISIONED,
                    $user,
                    ['role' => $role],
                    actor: $user,
                    organizationId: $organization->id,
                );

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // La cuenta se creó a la vez por otra vía: ya no es nueva, así que no se toma.
            throw new SsoException(SsoException::NOT_MEMBER);
        }
    }

    /**
     * @throws SsoException
     */
    private function decide(Organization $organization, SsoConnection $connection, ?User $user): string
    {
        if ($user !== null) {
            // Una cuenta eliminada no se recupera por SSO.
            if ($user->trashed() || $user->isBlocked()) {
                throw new SsoException(SsoException::BLOCKED);
            }
            // Un IdP de cliente nunca da acceso a la administración de la plataforma.
            if ($user->isPlatformAdmin()) {
                throw new SsoException(SsoException::PLATFORM_ADMIN);
            }

            $membership = $organization->users()->where('users.id', $user->id)->first()?->pivot;
            if ($membership === null) {
                // Una cuenta que ya existe sólo entra si ya es miembro (por invitación):
                // el IdP de una organización nunca se apropia de una cuenta ajena.
                throw new SsoException(SsoException::NOT_MEMBER);
            }
            if ($membership->getAttribute('status') !== MembershipStatus::ACTIVE->value) {
                throw new SsoException(SsoException::SUSPENDED);
            }

            return self::MEMBER;
        }

        if (! $connection->jit_provisioning) {
            throw new SsoException(SsoException::NOT_MEMBER);
        }

        // Mismas plazas que al invitar: miembros + invitaciones pendientes.
        $projected = $organization->users()->count()
            + $organization->invitations()->where('status', InvitationStatus::PENDING->value)->count();
        if (! $this->entitlements->withinLimit($organization, Entitlement::TEAM_MEMBERS_MAX, $projected)) {
            throw new SsoException(SsoException::SEATS);
        }

        return self::PROVISION;
    }

    private function existing(string $email): ?User
    {
        return User::query()->withTrashed()->where('email', $email)->first();
    }
}
