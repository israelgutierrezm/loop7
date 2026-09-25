<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Enums\InvitationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Notifications\OrganizationInvitationNotification;
use App\Modules\Organizations\Services\MembershipService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementsService $entitlements,
        private readonly MembershipService $memberships,
    ) {
    }

    public function handle(Organization $organization, string $email, string $role, User $invitedBy): OrganizationInvitation
    {
        $this->assertValidRole($role);

        // Nadie puede invitar con un rol que no podría asignar (p. ej. un MANAGER, ADMIN).
        if (! in_array($role, $this->memberships->assignableRoles($invitedBy, $organization), true)) {
            throw ValidationException::withMessages([
                'role' => 'No puedes invitar con ese rol.',
            ]);
        }

        $email = mb_strtolower(trim($email));

        // No invitar a quien ya es miembro.
        $alreadyMember = $organization->users()->where('email', $email)->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => 'Esta persona ya es miembro de la organización.',
            ]);
        }

        // Límite de plan: miembros del equipo (activos + invitaciones pendientes).
        $projected = $organization->users()->count()
            + $organization->invitations()->where('status', InvitationStatus::PENDING->value)->count();
        if (! $this->entitlements->withinLimit($organization, Entitlement::TEAM_MEMBERS_MAX, $projected)) {
            throw new PlanLimitExceededException(
                'Has alcanzado el número de miembros incluidos en tu plan.',
                Entitlement::TEAM_MEMBERS_MAX,
            );
        }

        $plainToken = Str::random(48);

        $invitation = new OrganizationInvitation([
            'organization_id' => $organization->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $plainToken),
            'invited_by_user_id' => $invitedBy->id,
            'status' => InvitationStatus::PENDING->value,
            'expires_at' => now()->addHours((int) config('platform.invitation_ttl_hours', 72)),
        ]);
        // organization_id ya está asignado explícitamente; el trait lo respeta.
        $invitation->save();

        Notification::route('mail', $email)
            ->notify(new OrganizationInvitationNotification($invitation, $plainToken));

        $this->audit->log(
            AuditAction::MEMBER_INVITED,
            $invitation,
            ['email' => $email, 'role' => $role],
            actor: $invitedBy,
            organizationId: $organization->id,
        );

        return $invitation;
    }

    private function assertValidRole(string $role): void
    {
        if (! in_array($role, OrganizationRole::values(), true)) {
            throw ValidationException::withMessages([
                'role' => 'El rol indicado no es válido.',
            ]);
        }

        if ($role === OrganizationRole::OWNER->value) {
            throw ValidationException::withMessages([
                'role' => 'No se puede invitar directamente como propietario.',
            ]);
        }
    }
}
