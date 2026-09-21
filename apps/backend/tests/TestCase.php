<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Modules\AccessControl\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Organizations\Actions\CreateOrganizationForUser;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Database\Seeders\PaymentGatewaySeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Simula peticiones del SPA para que Sanctum trate la request como
        // stateful (sesión disponible), igual que en producción.
        $this->withHeader('Origin', (string) config('app.frontend_url'));
    }

    /**
     * Siembra datos base (roles/permisos + planes/entitlements + pasarelas).
     * Necesario para casi todos los tests: la creación de Organizations dispara
     * el trial, y los límites de plan dependen de que existan los planes.
     */
    protected function seedRbac(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingSeeder::class);
        $this->seed(PaymentGatewaySeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Crea un usuario propietario junto con su Organization.
     *
     * @param  array<string, mixed>  $userAttributes
     * @return array{0: User, 1: Organization}
     */
    protected function createOwnerWithOrganization(array $userAttributes = [], string $orgName = 'Org de prueba'): array
    {
        $user = User::factory()->create($userAttributes);
        $organization = app(CreateOrganizationForUser::class)->handle($user, $orgName);

        return [$user->fresh(), $organization->fresh()];
    }

    /**
     * Añade un miembro con un rol concreto a una Organization.
     *
     * @param  array<string, mixed>  $userAttributes
     */
    protected function addMember(
        Organization $organization,
        string $role,
        bool $allBrandsAccess = true,
        array $userAttributes = [],
    ): User {
        $user = User::factory()->create($userAttributes);

        $organization->users()->attach($user->id, [
            'status' => MembershipStatus::ACTIVE->value,
            'all_brands_access' => $allBrandsAccess,
            'joined_at' => now(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $user->unsetRelation('roles');
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * Cambia el plan de una Organization (para probar límites de plan).
     */
    protected function setOrganizationPlan(Organization $organization, string $planKey): void
    {
        $plan = \App\Modules\Billing\Models\Plan::query()->where('key', $planKey)->firstOrFail();
        app(\App\Modules\Billing\Services\SubscriptionService::class)
            ->activatePlan($organization, $plan, 'month', 'manual');
    }

    /**
     * Autentica como usuario y fija el header de Organization para el tenant.
     */
    protected function actingInOrganization(User $user, ?Organization $organization = null): static
    {
        Sanctum::actingAs($user);

        if ($organization !== null) {
            $this->withHeader('X-Organization', $organization->public_id);
        }

        return $this;
    }
}
