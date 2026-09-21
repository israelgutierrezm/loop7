<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Enums\InvitationStatus;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AcceptInvitation
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(string $plainToken, User $user): Organization
    {
        $invitation = OrganizationInvitation::query()
            ->withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if ($invitation === null || ! $invitation->isPending() || $invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => 'La invitación no es válida o ha expirado.',
            ]);
        }

        // El correo de la invitación debe coincidir con el del usuario que la acepta.
        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            throw ValidationException::withMessages([
                'token' => 'Esta invitación fue enviada a otro correo.',
            ]);
        }

        return DB::transaction(function () use ($invitation, $user): Organization {
            /** @var Organization $organization */
            $organization = Organization::query()->findOrFail($invitation->organization_id);

            if (! $organization->users()->where('users.id', $user->id)->exists()) {
                $organization->users()->attach($user->id, [
                    'status' => MembershipStatus::ACTIVE->value,
                    'all_brands_access' => true,
                    'joined_at' => now(),
                ]);
            }

            $this->registrar->setPermissionsTeamId($organization->id);
            $user->unsetRelation('roles');
            $user->syncRoles([$invitation->role]);

            $invitation->update([
                'status' => InvitationStatus::ACCEPTED->value,
                'accepted_at' => now(),
            ]);

            $this->audit->log(
                AuditAction::MEMBER_JOINED,
                $user,
                ['role' => $invitation->role],
                actor: $user,
                organizationId: $organization->id,
            );

            return $organization;
        });
    }
}
