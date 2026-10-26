<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\TwoFactorRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Control Enterprise «Exigir la verificación en dos pasos» (docs/03): sólo con
 * el plan, sin dejar fuera a quien lo activa, bloquea la organización a quien no
 * lo tenga (salvo el contexto y Mi perfil) y exime las sesiones del SSO.
 */
class RequireTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function withTwoFactor(User $user): User
    {
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $user->fresh() ?? $user;
    }

    /**
     * @return array{0: User, 1: Organization, 2: User}
     */
    private function scenario(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'enterprise');
        $member = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);

        return [$this->withTwoFactor($owner), $org, $member];
    }

    public function test_activarlo_exige_plan_permiso_y_tenerlo_una_misma(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional');
        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/organization/security')
            ->assertOk()
            ->assertJsonPath('data.available', false);
        $this->putJson('/api/v1/organization/security', ['require_two_factor' => true])->assertStatus(402);

        $this->setOrganizationPlan($org, 'enterprise');
        app(EntitlementsService::class)->flush();
        $this->putJson('/api/v1/organization/security', ['require_two_factor' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.require_two_factor.0', 'Activa primero tu verificación en dos pasos en Mi perfil.');

        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);
        $this->actingInOrganization($viewer, $org)->putJson('/api/v1/organization/security', ['require_two_factor' => true])->assertForbidden();

        $owner = $this->withTwoFactor($owner);
        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/organization/security', ['require_two_factor' => true])
            ->assertOk()
            ->assertJsonPath('data.require_two_factor', true)
            ->assertJsonPath('data.members_without_two_factor', 1);
        $this->assertTrue($org->fresh()?->require_two_factor);
        $this->assertSame(1, AuditLog::query()->where('action', 'organization.security_updated')->count());
    }

    public function test_bloquea_la_organizacion_a_quien_no_lo_tiene(): void
    {
        [$owner, $org, $member] = $this->scenario();
        $org->forceFill(['require_two_factor' => true])->save();

        $this->actingInOrganization($member, $org)
            ->getJson('/api/v1/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');
        // El contexto sí, para que el SPA lo sepa; y su perfil, para activarlo.
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.two_factor_required', true);
        $this->getJson('/api/v1/me')->assertOk();

        $member = $this->withTwoFactor($member);
        $this->actingInOrganization($member, $org)->getJson('/api/v1/dashboard')->assertOk();
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.two_factor_required', false);
        $this->actingInOrganization($owner, $org)->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_sin_el_plan_o_desactivado_no_rige(): void
    {
        [, $org, $member] = $this->scenario();
        $org->forceFill(['require_two_factor' => true])->save();

        $this->setOrganizationPlan($org, 'professional');
        app(EntitlementsService::class)->flush();
        $this->actingInOrganization($member, $org)->getJson('/api/v1/dashboard')->assertOk();

        $this->setOrganizationPlan($org, 'enterprise');
        app(EntitlementsService::class)->flush();
        $org->forceFill(['require_two_factor' => false])->save();
        $this->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_las_sesiones_del_sso_de_la_organizacion_quedan_exentas(): void
    {
        [, $org, $member] = $this->scenario();
        $org->forceFill(['require_two_factor' => true])->save();

        $this->actingInOrganization($member, $org)
            ->withSession([TwoFactorRequirement::SSO_SESSION_KEY => $org->id])
            ->getJson('/api/v1/dashboard')
            ->assertOk();

        // El SSO de otra organización no cuenta.
        $this->actingInOrganization($member, $org)
            ->withSession([TwoFactorRequirement::SSO_SESSION_KEY => $org->id + 1000])
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }
}
