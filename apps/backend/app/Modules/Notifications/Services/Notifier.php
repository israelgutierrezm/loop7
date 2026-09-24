<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\MembershipService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Punto de entrada para avisar a miembros de una Organization. Sólo llega a
 * miembros activos y, si el aviso es de una Brand, con acceso a ella.
 */
class Notifier
{
    public function __construct(private readonly MembershipService $memberships)
    {
    }

    /**
     * Avisa a quienes tienen un permiso (p. ej. content.approve).
     *
     * @param  list<int>  $except  usuarios a excluir (normalmente, quien originó el evento)
     * @return int nº de destinatarios
     */
    public function toMembersWithPermission(
        string $permission,
        OrganizationNotice $notice,
        ?int $brandId = null,
        array $except = [],
    ): int {
        $organization = Organization::query()->find($notice->organizationId);
        if ($organization === null) {
            return 0;
        }

        return $this->send(
            $this->memberships->membersWithPermission($organization, $permission, $brandId),
            $notice,
            $except,
        );
    }

    /**
     * Avisa a usuarios concretos (autor, aprobador, asignado…).
     *
     * @param  list<int|null>  $userIds
     * @param  list<int>  $except
     * @return int nº de destinatarios
     */
    public function toUsers(array $userIds, OrganizationNotice $notice, ?int $brandId = null, array $except = []): int
    {
        $organization = Organization::query()->find($notice->organizationId);
        $ids = array_values(array_unique(array_filter($userIds, fn (?int $id): bool => $id !== null)));

        if ($organization === null || $ids === []) {
            return 0;
        }

        return $this->send($this->memberships->activeMembers($organization, $ids, $brandId), $notice, $except);
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  list<int>  $except
     */
    private function send(Collection $recipients, OrganizationNotice $notice, array $except): int
    {
        $recipients = $recipients->reject(fn (User $user): bool => in_array($user->id, $except, true))->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notice);
        }

        return $recipients->count();
    }
}
