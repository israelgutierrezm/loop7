<?php

declare(strict_types=1);

namespace Tests\Feature\Sso;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Models\OrganizationEntitlementOverride;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Dns\DnsResolver;
use App\Modules\Sso\Models\OrganizationDomain;
use App\Modules\Sso\Models\SsoConnection;
use App\Modules\Sso\Services\SamlSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeDnsResolver;
use Tests\Support\SamlIdp;
use Tests\TestCase;

/**
 * Inicio de sesión único SAML (docs/03): dominios verificados por DNS,
 * configuración, flujo completo con respuestas firmadas, rechazos (firma,
 * repetición, dominio, destino), alta automática, código atado al navegador,
 * SSO obligatorio, prueba de conexión y aislamiento entre organizaciones.
 */
class SsoTest extends TestCase
{
    use RefreshDatabase;

    private FakeDnsResolver $dns;

    private SamlIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->dns = new FakeDnsResolver();
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->idp = new SamlIdp();
    }

    /**
     * Organización Enterprise con empresa.com verificado y el SSO activo.
     *
     * @param  array<string, mixed>  $connection
     * @return array{0: User, 1: Organization}
     */
    private function scenario(array $connection = []): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization(['email' => 'duena@empresa.com']);
        $this->setOrganizationPlan($org, 'enterprise');
        $this->verifiedDomain($org, 'empresa.com');
        SsoConnection::query()->create($connection + [
            'organization_id' => $org->id,
            'is_enabled' => true,
            'idp_entity_id' => SamlIdp::ENTITY_ID,
            'idp_sso_url' => SamlIdp::SSO_URL,
            'idp_certificate' => $this->idp->certificate(),
            'default_role' => OrganizationRole::CONTENT_CREATOR->value,
        ]);

        return [$owner, $org];
    }

    private function verifiedDomain(Organization $org, string $domain): OrganizationDomain
    {
        $record = OrganizationDomain::query()->create([
            'organization_id' => $org->id, 'domain' => $domain, 'verification_token' => str_repeat('a', 40),
        ]);
        $record->forceFill(['verified_at' => now(), 'verified_domain' => $domain])->save();

        return $record;
    }

    private function verifier(): string
    {
        return str_repeat('v', 50);
    }

    /**
     * Pide el inicio de sesión para un correo; devuelve la URL del IdP.
     */
    private function discover(string $email, ?string $verifier = null): string
    {
        return (string) $this->postJson('/api/v1/sso/discover', [
            'email' => $email,
            'challenge' => hash('sha256', $verifier ?? $this->verifier()),
        ])->assertOk()->json('data.redirect_url');
    }

    /**
     * El IdP responde a la petición de esa URL (lo que el navegador envía al ACS).
     *
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>  $options
     */
    private function acs(Organization $org, string $redirectUrl, string $email, array $attributes = [], array $options = [], ?SamlIdp $idp = null): TestResponse
    {
        $response = ($idp ?? $this->idp)->response(
            SamlSettings::acsUrl($org),
            SamlSettings::entityId($org),
            SamlIdp::requestIdFrom($redirectUrl),
            $email,
            $attributes + ['email' => $email],
            $options,
        );

        return $this->post("/api/v1/sso/{$org->public_id}/acs", [
            'SAMLResponse' => $response,
            'RelayState' => SamlIdp::relayStateFrom($redirectUrl),
        ]);
    }

    private function codeFrom(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(config('app.frontend_url') . '/sso/callback#code=', $location);

        return substr($location, strpos($location, '#code=') + 6);
    }

    private function assertLoginError(TestResponse $response, string $reason): void
    {
        $response->assertStatus(303);
        $this->assertSame(config('app.frontend_url') . '/login?sso_error=' . $reason, $response->headers->get('Location'));
    }

    public function test_los_dominios_se_verifican_con_un_registro_txt(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'enterprise');

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/sso/domains', ['domain' => 'gmail.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'No se pueden usar dominios de correo gratuito.');
        $this->postJson('/api/v1/organization/sso/domains', ['domain' => 'no es dominio'])->assertUnprocessable();

        $domain = $this->postJson('/api/v1/organization/sso/domains', ['domain' => 'https://www.Empresa.com/contacto'])
            ->assertCreated()
            ->assertJsonPath('data.domain', 'empresa.com')
            ->assertJsonPath('data.verified', false)
            ->json('data');
        $this->assertStringStartsWith('loop7-verification=', $domain['txt_value']);
        $this->postJson('/api/v1/organization/sso/domains', ['domain' => 'ana@empresa.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'Ya añadiste ese dominio.');

        // Sin el registro TXT aún.
        $this->postJson("/api/v1/organization/sso/domains/{$domain['id']}/verify")
            ->assertOk()
            ->assertJsonPath('data.verified', false);

        $this->dns->records['empresa.com'] = ['v=spf1 -all', $domain['txt_value']];
        $this->postJson("/api/v1/organization/sso/domains/{$domain['id']}/verify")
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('message', 'Dominio verificado.');

        $this->assertSame('empresa.com', OrganizationDomain::query()->sole()->verified_domain);
        $this->assertSame(1, AuditLog::query()->where('action', 'sso.domain_added')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'sso.domain_verified')->count());
    }

    public function test_un_dominio_verificado_solo_tiene_una_organizacion_y_no_se_ve_desde_otra(): void
    {
        [, $orgA] = $this->scenario();
        [$ownerB, $orgB] = $this->createOwnerWithOrganization([], 'Otra');
        $this->setOrganizationPlan($orgB, 'enterprise');
        $domainA = OrganizationDomain::query()->withoutGlobalScopes()->where('organization_id', $orgA->id)->sole();

        $this->actingInOrganization($ownerB, $orgB)
            ->postJson('/api/v1/organization/sso/domains', ['domain' => 'empresa.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'Ese dominio ya está verificado por otra organización.');

        // Sin IDOR: el dominio de A no existe para B.
        $this->postJson("/api/v1/organization/sso/domains/{$domainA->public_id}/verify")->assertNotFound();
        $this->deleteJson("/api/v1/organization/sso/domains/{$domainA->public_id}")->assertNotFound();
        $this->getJson('/api/v1/organization/sso')
            ->assertOk()
            ->assertJsonCount(0, 'data.domains')
            ->assertJsonPath('data.connection.configured', false);
    }

    public function test_configurar_exige_permiso_plan_y_datos_coherentes(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $payload = [
            'is_enabled' => true, 'enforced' => false, 'idp_entity_id' => SamlIdp::ENTITY_ID, 'idp_sso_url' => SamlIdp::SSO_URL,
            'idp_certificate' => $this->idp->certificate(), 'jit_provisioning' => true, 'default_role' => 'CONTENT_CREATOR',
        ];

        // Sin el plan Enterprise.
        $this->setOrganizationPlan($org, 'growth');
        $this->actingInOrganization($owner, $org)->putJson('/api/v1/organization/sso', $payload)->assertStatus(402);
        $this->getJson('/api/v1/organization/sso')->assertOk()->assertJsonPath('data.available', false);

        $this->setOrganizationPlan($org, 'enterprise');
        $analyst = $this->addMember($org, OrganizationRole::ANALYST->value);
        $this->actingInOrganization($analyst, $org)->putJson('/api/v1/organization/sso', $payload)->assertForbidden();
        $this->getJson('/api/v1/organization/sso')->assertForbidden();

        // Activar exige un dominio verificado; obligatorio exige activado.
        $this->actingInOrganization($owner, $org)->putJson('/api/v1/organization/sso', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_enabled.0', 'Verifica al menos un dominio antes de activar el SSO.');
        $this->putJson('/api/v1/organization/sso', ['is_enabled' => false, 'enforced' => true] + $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.enforced.0', 'Activa el SSO antes de hacerlo obligatorio.');
        $this->putJson('/api/v1/organization/sso', ['idp_certificate' => 'no es un certificado', 'is_enabled' => false] + $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idp_certificate');
        $this->putJson('/api/v1/organization/sso', ['idp_sso_url' => 'http://idp.example.test/sso', 'is_enabled' => false] + $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idp_sso_url');
        $this->putJson('/api/v1/organization/sso', ['default_role' => 'OWNER', 'is_enabled' => false] + $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_role');

        $this->verifiedDomain($org, 'empresa.com');
        $this->putJson('/api/v1/organization/sso', $payload)
            ->assertOk()
            ->assertJsonPath('data.connection.is_enabled', true)
            ->assertJsonPath('data.connection.configured', true)
            ->assertJsonPath('data.connection.certificates.0.subject', 'idp.example.test')
            ->assertJsonPath('data.service_provider.acs_url', SamlSettings::acsUrl($org));

        $audit = AuditLog::query()->where('action', 'sso.connection_updated')->sole();
        $this->assertTrue($audit->properties['values']['is_enabled']);
        $this->assertContains('idp_certificate', $audit->properties['changes']);
        // El certificado no se copia a la auditoría.
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', json_encode($audit->properties) ?: '');
    }

    public function test_metadatos_del_sp_y_lectura_de_los_del_idp(): void
    {
        [$owner, $org] = $this->scenario();

        $xml = $this->get("/api/v1/sso/{$org->public_id}/metadata")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();
        $this->assertStringContainsString('entityID="' . SamlSettings::entityId($org) . '"', (string) $xml);
        $this->assertStringContainsString('Location="' . SamlSettings::acsUrl($org) . '"', (string) $xml);
        $this->assertStringContainsString('WantAssertionsSigned="true"', (string) $xml);

        // Sin el plan no hay metadatos.
        $this->setOrganizationPlan($org, 'growth');
        $this->get("/api/v1/sso/{$org->public_id}/metadata")->assertNotFound();
        $this->setOrganizationPlan($org, 'enterprise');

        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $this->idp->certificate());
        $idpMetadata = '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="' . SamlIdp::ENTITY_ID . '">'
            . '<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            . '<md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>' . $body . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>'
            . '<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="https://idp.example.test/post"/>'
            . '<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="' . SamlIdp::SSO_URL . '"/>'
            . '</md:IDPSSODescriptor></md:EntityDescriptor>';

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/sso/metadata', ['xml' => $idpMetadata])
            ->assertOk()
            ->assertJsonPath('data.idp_entity_id', SamlIdp::ENTITY_ID)
            ->assertJsonPath('data.idp_sso_url', SamlIdp::SSO_URL);
        $this->postJson('/api/v1/organization/sso/metadata', ['xml' => '<!DOCTYPE x [<!ENTITY a "b">]><x>&a;</x>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('xml');
    }

    public function test_inicio_de_sesion_completo_con_el_idp(): void
    {
        [, $org] = $this->scenario();
        $ana = $this->addMember($org, OrganizationRole::MANAGER->value, userAttributes: ['email' => 'ana@empresa.com']);

        $url = $this->discover('Ana@Empresa.com');
        $this->assertStringStartsWith(SamlIdp::SSO_URL . '?SAMLRequest=', $url);
        $this->assertNotSame('', SamlIdp::relayStateFrom($url));

        $response = $this->acs($org, $url, 'ana@empresa.com', ['displayName' => 'Ana López']);
        $response->assertStatus(303);
        $code = $this->codeFrom($response);

        $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'verifier' => $this->verifier()])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ana@empresa.com')
            ->assertJsonPath('data.organization', $org->public_id);
        $this->assertAuthenticatedAs($ana, 'web');

        $this->assertSame(1, AuditLog::query()->where('action', 'sso.login')->where('user_id', $ana->id)->count());
        $this->assertNotNull(SsoConnection::query()->withoutGlobalScopes()->sole()->last_login_at);
        $this->assertNotNull($ana->fresh()?->last_login_at);
    }

    public function test_se_rechaza_una_respuesta_con_firma_ajena_sin_firma_o_para_otro_destino(): void
    {
        [, $org] = $this->scenario();
        $this->addMember($org, OrganizationRole::VIEWER->value, userAttributes: ['email' => 'ana@empresa.com']);
        [, $other] = $this->createOwnerWithOrganization([], 'Otra');

        $this->assertLoginError($this->acs($org, $this->discover('ana@empresa.com'), 'ana@empresa.com', idp: new SamlIdp('atacante')), 'invalid_response');
        $this->assertLoginError($this->acs($org, $this->discover('ana@empresa.com'), 'ana@empresa.com', options: ['sign' => false]), 'invalid_response');
        $this->assertLoginError($this->acs($org, $this->discover('ana@empresa.com'), 'ana@empresa.com', options: ['destination' => SamlSettings::acsUrl($other)]), 'invalid_response');
        $this->assertLoginError($this->acs($org, $this->discover('ana@empresa.com'), 'ana@empresa.com', options: ['audience' => 'https://otro-sp.test']), 'invalid_response');
        $this->assertLoginError($this->acs($org, $this->discover('ana@empresa.com'), 'ana@empresa.com', options: ['issuer' => 'https://otro-idp.test']), 'invalid_response');

        $this->assertSame(5, AuditLog::query()->where('action', 'sso.login_failed')->where('organization_id', $org->id)->count());
        $this->assertGuest('web');
    }

    public function test_no_se_aceptan_respuestas_repetidas_ni_no_solicitadas(): void
    {
        [, $org] = $this->scenario();
        $this->addMember($org, OrganizationRole::VIEWER->value, userAttributes: ['email' => 'ana@empresa.com']);
        $url = $this->discover('ana@empresa.com');
        $relay = SamlIdp::relayStateFrom($url);
        $saml = $this->idp->response(SamlSettings::acsUrl($org), SamlSettings::entityId($org), SamlIdp::requestIdFrom($url), 'ana@empresa.com', ['email' => 'ana@empresa.com']);
        $post = fn () => $this->post("/api/v1/sso/{$org->public_id}/acs", ['SAMLResponse' => $saml, 'RelayState' => $relay]);

        $snapshot = Cache::get('sso:relay:' . hash('sha256', $relay));
        $this->codeFrom($post());

        // El RelayState es de un solo uso.
        $this->assertLoginError($post(), 'expired');

        // Aunque se recuperara, la aserción ya se usó.
        Cache::put('sso:relay:' . hash('sha256', $relay), $snapshot, 600);
        $this->assertLoginError($post(), 'invalid_response');

        // Iniciada por el IdP (sin petición nuestra) o sin datos.
        $this->assertLoginError($this->post("/api/v1/sso/{$org->public_id}/acs", ['SAMLResponse' => $saml, 'RelayState' => 'inventado']), 'expired');
        $this->assertLoginError($this->post("/api/v1/sso/{$org->public_id}/acs", []), 'invalid_response');
        $this->assertLoginError($this->post('/api/v1/sso/01ARZ3NDEKTSV4RRFFQ69G5FAV/acs', ['SAMLResponse' => $saml, 'RelayState' => $relay]), 'not_enabled');
    }

    public function test_el_correo_debe_ser_de_un_dominio_verificado_de_la_organizacion(): void
    {
        [, $org] = $this->scenario();
        $this->addMember($org, OrganizationRole::VIEWER->value, userAttributes: ['email' => 'ana@otra.com']);

        $this->postJson('/api/v1/sso/discover', ['email' => 'ana@otra.com', 'challenge' => hash('sha256', $this->verifier())])
            ->assertNotFound()
            ->assertJsonPath('code', 'sso_not_available');

        // El IdP intenta colar un correo de un dominio ajeno.
        $this->assertLoginError($this->acs($org, $this->discover('jefe@empresa.com'), 'ana@otra.com'), 'domain_not_verified');
        $failed = AuditLog::query()->where('action', 'sso.login_failed')->sole();
        $this->assertSame('ana@otra.com', $failed->properties['email']);
    }

    public function test_sin_alta_automatica_solo_entran_los_miembros(): void
    {
        [, $org] = $this->scenario();
        $suspended = $this->addMember($org, OrganizationRole::VIEWER->value, userAttributes: ['email' => 'pausa@empresa.com']);
        $org->users()->updateExistingPivot($suspended->id, ['status' => MembershipStatus::SUSPENDED->value]);
        User::factory()->create(['email' => 'admin@empresa.com', 'is_platform_admin' => true]);

        $this->assertLoginError($this->acs($org, $this->discover('nuevo@empresa.com'), 'nuevo@empresa.com'), 'not_member');
        $this->assertLoginError($this->acs($org, $this->discover('pausa@empresa.com'), 'pausa@empresa.com'), 'suspended');
        $this->assertLoginError($this->acs($org, $this->discover('admin@empresa.com'), 'admin@empresa.com'), 'platform_admin');
        $this->assertNull(User::query()->where('email', 'nuevo@empresa.com')->first());
    }

    public function test_alta_automatica_con_el_rol_por_defecto_y_las_plazas_del_plan(): void
    {
        [, $org] = $this->scenario(['jit_provisioning' => true]);
        // Una cuenta que ya existe en otra organización no se toma por SSO.
        $this->createOwnerWithOrganization(['email' => 'freelance@empresa.com'], 'Su propia org');

        $code = $this->codeFrom($this->acs($org, $this->discover('nueva@empresa.com'), 'nueva@empresa.com', ['givenName' => 'Nora', 'sn' => 'Ruiz']));
        $user = User::query()->where('email', 'nueva@empresa.com')->sole();
        $this->assertSame('Nora Ruiz', $user->name);
        $this->assertNotNull($user->email_verified_at);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $this->assertTrue($user->hasRole(OrganizationRole::CONTENT_CREATOR->value));
        $this->assertSame(1, AuditLog::query()->where('action', 'sso.user_provisioned')->count());
        $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'verifier' => $this->verifier()])->assertOk();

        $this->assertLoginError($this->acs($org, $this->discover('freelance@empresa.com'), 'freelance@empresa.com'), 'not_member');

        // Si el rol por defecto deja de existir (rol personalizado eliminado), entra como VIEWER.
        SsoConnection::query()->withoutGlobalScopes()->update(['default_role' => 'custom_eliminado']);
        $this->codeFrom($this->acs($org, $this->discover('tercera@empresa.com'), 'tercera@empresa.com'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $this->assertTrue(User::query()->where('email', 'tercera@empresa.com')->sole()->hasRole(OrganizationRole::VIEWER->value));

        // Plazas: ya hay 3 miembros (propietaria y las dos nuevas).
        OrganizationEntitlementOverride::query()->create(['organization_id' => $org->id, 'entitlement_key' => Entitlement::TEAM_MEMBERS_MAX, 'value' => '3']);
        app(EntitlementsService::class)->flush();
        $this->assertLoginError($this->acs($org, $this->discover('otra@empresa.com'), 'otra@empresa.com'), 'seats');
    }

    public function test_eliminar_la_organizacion_libera_sus_dominios(): void
    {
        [, $org] = $this->scenario();
        [$ownerB, $orgB] = $this->createOwnerWithOrganization([], 'Otra');
        $this->setOrganizationPlan($orgB, 'enterprise');

        event(new OrganizationDeleted($org));
        $this->assertSame(0, OrganizationDomain::query()->withoutGlobalScopes()->where('organization_id', $org->id)->count());
        $this->assertSame(0, SsoConnection::query()->withoutGlobalScopes()->count());

        // Ya puede verificarlo otra organización.
        $this->actingInOrganization($ownerB, $orgB)
            ->postJson('/api/v1/organization/sso/domains', ['domain' => 'empresa.com'])
            ->assertCreated();
    }

    public function test_el_codigo_es_de_un_solo_uso_y_del_navegador_que_inicio(): void
    {
        [, $org] = $this->scenario();
        $this->addMember($org, OrganizationRole::VIEWER->value, userAttributes: ['email' => 'ana@empresa.com']);
        $code = $this->codeFrom($this->acs($org, $this->discover('ana@empresa.com'), 'ana@empresa.com'));

        // Otro navegador (sin el verificador original) no puede usarlo…
        $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'verifier' => str_repeat('x', 50)])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'sso_failed');
        // …y queda gastado.
        $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'verifier' => $this->verifier()])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_sso_obligatorio_bloquea_la_contrasena_salvo_a_la_propietaria(): void
    {
        [$owner, $org] = $this->scenario(['enforced' => true]);
        $ana = $this->addMember($org, OrganizationRole::ADMIN->value, userAttributes: ['email' => 'ana@empresa.com', 'password' => 'secreto-largo-123']);
        [$outsider] = $this->createOwnerWithOrganization(['email' => 'externo@empresa.com', 'password' => 'secreto-largo-123'], 'Ajena');
        $owner->forceFill(['password' => bcrypt('secreto-largo-123')])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@empresa.com', 'password' => 'secreto-largo-123'])
            ->assertForbidden()
            ->assertJsonPath('code', 'sso_required');
        $this->assertGuest('web');
        $this->assertSame(1, AuditLog::query()->where('action', 'sso.password_login_blocked')->where('user_id', $ana->id)->count());

        // Acceso de emergencia de la propietaria y cuentas que no son miembros.
        $this->postJson('/api/v1/auth/login', ['email' => 'duena@empresa.com', 'password' => 'secreto-largo-123'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $outsider->email, 'password' => 'secreto-largo-123'])->assertOk();
        $this->app['auth']->forgetGuards();

        // Sin obligatoriedad vuelve la contraseña.
        SsoConnection::query()->withoutGlobalScopes()->update(['enforced' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@empresa.com', 'password' => 'secreto-largo-123'])->assertOk();
    }

    public function test_prueba_de_conexion_sin_iniciar_sesion(): void
    {
        [$owner, $org] = $this->scenario(['is_enabled' => false]);
        $admin = $this->addMember($org, OrganizationRole::ADMIN->value);

        $url = (string) $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/organization/sso/test')
            ->assertOk()
            ->json('data.redirect_url');

        $response = $this->acs($org, $url, 'duena@empresa.com', ['displayName' => 'Dueña', 'department' => 'Marketing']);
        $response->assertStatus(303);
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(config('app.frontend_url') . '/app/settings#sso_test=', $location);
        $token = substr($location, strpos($location, '=') + 1);

        $this->getJson("/api/v1/organization/sso/test/{$token}")
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.email', 'duena@empresa.com')
            ->assertJsonPath('data.name', 'Dueña')
            ->assertJsonPath('data.outcome', 'member')
            ->assertJsonPath('data.attributes.department', 'Marketing');

        // Sólo quien hizo la prueba ve el resultado; nadie inició sesión.
        $this->actingInOrganization($admin, $org)->getJson("/api/v1/organization/sso/test/{$token}")->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('action', 'sso.login')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'sso.tested')->count());

        // Un fallo se explica a quien administra.
        $url = (string) $this->actingInOrganization($owner, $org)->postJson('/api/v1/organization/sso/test')->json('data.redirect_url');
        $location = (string) $this->acs($org, $url, 'duena@empresa.com', idp: new SamlIdp('atacante'))->headers->get('Location');
        $token = substr($location, strpos($location, '=') + 1);
        $this->getJson("/api/v1/organization/sso/test/{$token}")
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.reason', 'invalid_response')
            ->assertJsonPath('data.detail', 'Signature validation failed. SAML Response rejected');
    }

    public function test_quitar_el_ultimo_dominio_verificado_exige_desactivar_el_sso(): void
    {
        [$owner, $org] = $this->scenario();
        $domain = OrganizationDomain::query()->withoutGlobalScopes()->where('organization_id', $org->id)->sole();

        $this->actingInOrganization($owner, $org)
            ->deleteJson("/api/v1/organization/sso/domains/{$domain->public_id}")
            ->assertUnprocessable();

        SsoConnection::query()->withoutGlobalScopes()->update(['is_enabled' => false]);
        $this->deleteJson("/api/v1/organization/sso/domains/{$domain->public_id}")->assertOk();
        $this->assertSame(0, OrganizationDomain::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'sso.domain_removed')->count());
    }
}
