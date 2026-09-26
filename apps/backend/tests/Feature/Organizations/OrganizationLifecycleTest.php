<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Api\Models\ApiKey;
use App\Modules\Api\Services\ApiKeyService;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Services\MembershipService;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secreta-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function owner(): array
    {
        return $this->createOwnerWithOrganization(['password' => self::PASSWORD], 'Agencia Sol');
    }

    public function test_el_propietario_elimina_la_organizacion_y_se_retira_todo_lo_suyo(): void
    {
        [$owner, $org] = $this->owner();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo', 'access_token' => 'TOKEN',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'd1', 'name' => 'Página', 'type' => 'page', 'access_token' => 'PAGE',
        ]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Programado',
            'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
        ]);
        $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => 'fake', 'body' => 'x']);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id, 'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id, 'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
        ]);
        $key = app(ApiKeyService::class)->generate($org, 'CRM', ['brands:read'])['model'];
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $org->id, 'email' => 'nuevo@loop7.test', 'role' => 'VIEWER',
            'token_hash' => hash('sha256', 'x'), 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);

        $this->actingInOrganization($owner, $org)
            ->deleteJson('/api/v1/organization', ['confirm_name' => 'agencia sol', 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertSoftDeleted('organizations', ['id' => $org->id]);
        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
        $this->assertSame('cancelled', $target->fresh()->status->value);
        $this->assertNull(SocialConnection::query()->withoutGlobalScopes()->withTrashed()->find($connection->id)->access_token);
        $this->assertFalse(ApiKey::query()->withoutGlobalScopes()->find($key->id)->is_active);
        $this->assertSame('revoked', OrganizationInvitation::query()->withoutGlobalScopes()->find($invitation->id)->status->value);
        $this->assertSame('cancelled', Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->first()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.deleted', 'organization_id' => $org->id]);

        // Ya no aparece entre las organizaciones del usuario.
        $this->assertCount(0, app(MembershipService::class)->organizationsWithRoles($owner->fresh()));
    }

    public function test_pide_el_nombre_exacto_y_la_contrasena(): void
    {
        [$owner, $org] = $this->owner();
        $api = $this->actingInOrganization($owner, $org);

        $api->deleteJson('/api/v1/organization', ['confirm_name' => 'Otra', 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_name');
        $api->deleteJson('/api/v1/organization', ['confirm_name' => 'Agencia Sol', 'password' => 'mala'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNotSoftDeleted('organizations', ['id' => $org->id]);
    }

    public function test_no_se_elimina_mientras_la_pasarela_siga_cobrando(): void
    {
        [$owner, $org] = $this->owner();
        $subscription = Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $subscription->forceFill(['status' => 'active', 'gateway' => 'stripe', 'gateway_subscription_id' => 'sub_123'])->save();
        $payload = ['confirm_name' => 'Agencia Sol', 'password' => self::PASSWORD];

        $this->actingInOrganization($owner, $org)->deleteJson('/api/v1/organization', $payload)
            ->assertStatus(409)->assertJsonPath('code', 'subscription_active');

        // Con la cancelación ya programada en la pasarela, sí.
        $subscription->forceFill(['cancel_at_period_end' => true])->save();
        $this->actingInOrganization($owner, $org)->deleteJson('/api/v1/organization', $payload)->assertOk();
    }

    public function test_solo_el_propietario_elimina(): void
    {
        [, $org] = $this->owner();
        $admin = $this->addMember($org, OrganizationRole::ADMIN->value, userAttributes: ['password' => self::PASSWORD]);

        $this->actingInOrganization($admin, $org)
            ->deleteJson('/api/v1/organization', ['confirm_name' => 'Agencia Sol', 'password' => self::PASSWORD])
            ->assertForbidden();
    }

    public function test_transferir_la_propiedad(): void
    {
        [$owner, $org] = $this->owner();
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value, allBrandsAccess: false);
        $memberships = app(MembershipService::class);

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/transfer-ownership', ['user' => $manager->public_id, 'password' => self::PASSWORD])
            ->assertOk();

        $org->refresh();
        $this->assertSame($manager->id, $org->owner_user_id);
        $this->assertSame([OrganizationRole::OWNER->value], $memberships->rolesFor($manager->fresh(), $org));
        $this->assertSame([OrganizationRole::ADMIN->value], $memberships->rolesFor($owner->fresh(), $org));
        $this->assertTrue((bool) $org->users()->whereKey($manager->id)->first()->pivot->all_brands_access);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.ownership_transferred']);

        // El anterior propietario ya no puede eliminarla.
        $this->actingInOrganization($owner->fresh(), $org)
            ->deleteJson('/api/v1/organization', ['confirm_name' => 'Agencia Sol', 'password' => self::PASSWORD])
            ->assertForbidden();
    }

    public function test_la_transferencia_exige_otro_miembro_activo_y_la_contrasena(): void
    {
        [$owner, $org] = $this->owner();
        $outsider = User::factory()->create();
        $admin = $this->addMember($org, OrganizationRole::ADMIN->value);
        $api = $this->actingInOrganization($owner, $org);

        $api->postJson('/api/v1/organization/transfer-ownership', ['user' => $outsider->public_id, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('user');
        $api->postJson('/api/v1/organization/transfer-ownership', ['user' => $owner->public_id, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('user');
        $api->postJson('/api/v1/organization/transfer-ownership', ['user' => $admin->public_id, 'password' => 'mala'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        // Un ADMIN no puede transferir una propiedad que no tiene.
        $this->actingInOrganization($admin, $org)
            ->postJson('/api/v1/organization/transfer-ownership', ['user' => $admin->public_id, 'password' => 'x'])
            ->assertForbidden();
    }

    public function test_crear_organizaciones_tiene_tope_por_propietario(): void
    {
        config(['platform.max_owned_organizations' => 2]);
        [$owner] = $this->owner();
        $api = $this->actingInOrganization($owner);

        $api->postJson('/api/v1/organizations', ['name' => 'Segunda'])->assertCreated();
        $api->postJson('/api/v1/organizations', ['name' => 'Tercera'])
            ->assertStatus(422)->assertJsonPath('code', 'organization_limit');
    }
}
