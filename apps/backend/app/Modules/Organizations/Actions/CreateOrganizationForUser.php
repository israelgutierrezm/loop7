<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea una Organization y establece al usuario como OWNER (membresía + rol).
 * Operación atómica.
 */
class CreateOrganizationForUser
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $user, string $name, array $attributes = []): Organization
    {
        return DB::transaction(function () use ($user, $name, $attributes): Organization {
            $organization = Organization::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_user_id' => $user->id,
                'status' => OrganizationStatus::ACTIVE,
                'billing_email' => $attributes['billing_email'] ?? $user->email,
                'country' => $attributes['country'] ?? null,
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'locale' => $attributes['locale'] ?? $user->locale ?? 'es',
            ]);

            $organization->users()->attach($user->id, [
                'status' => MembershipStatus::ACTIVE->value,
                'all_brands_access' => true,
                'joined_at' => now(),
            ]);

            // Asigna el rol OWNER dentro del "team" de esta Organization.
            $this->registrar->setPermissionsTeamId($organization->id);
            $user->assignRole(OrganizationRole::OWNER->value);

            $this->audit->log(
                action: AuditAction::ORGANIZATION_CREATED,
                auditable: $organization,
                properties: ['name' => $organization->name, 'slug' => $organization->slug],
                actor: $user,
                organizationId: $organization->id,
            );

            return $organization;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }
}
