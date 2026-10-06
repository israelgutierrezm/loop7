<?php

declare(strict_types=1);

namespace App\Modules\Sso\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Models\OrganizationDomain;
use App\Modules\Sso\Models\SsoConnection;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Validation\ValidationException;
use OneLogin\Saml2\IdPMetadataParser;
use Throwable;

/**
 * Configuración del SSO de una organización: lo que ve quien administra, los
 * cambios de la conexión (auditados) y la lectura de los metadatos del IdP.
 */
final class SsoConfiguration
{
    /** Campos cuyo valor se registra en la auditoría (los demás, sólo que cambiaron). */
    private const AUDITED_VALUES = ['is_enabled', 'enforced', 'jit_provisioning', 'default_role'];

    public function __construct(
        private readonly SsoService $sso,
        private readonly EntitlementsService $entitlements,
        private readonly AuditLogger $audit,
    ) {
    }

    public function available(Organization $organization): bool
    {
        return $this->entitlements->allows($organization, Entitlement::FEATURE_SSO);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Organization $organization): array
    {
        $connection = $this->sso->connectionOf($organization);
        $domains = OrganizationDomain::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->orderBy('domain')
            ->get();
        $certificates = $connection?->idp_certificate !== null ? (SamlSettings::inspect($connection->idp_certificate) ?? []) : [];

        return [
            'available' => $this->available($organization),
            'service_provider' => [
                'entity_id' => SamlSettings::entityId($organization),
                'acs_url' => SamlSettings::acsUrl($organization),
                'metadata_url' => SamlSettings::entityId($organization),
            ],
            'connection' => [
                'is_enabled' => (bool) $connection?->is_enabled,
                'enforced' => (bool) $connection?->enforced,
                'configured' => (bool) $connection?->isConfigured(),
                'idp_entity_id' => $connection?->idp_entity_id,
                'idp_sso_url' => $connection?->idp_sso_url,
                'idp_certificate' => $connection?->idp_certificate,
                'certificates' => array_map(fn (array $c): array => [
                    'subject' => $c['subject'],
                    'expires_at' => $c['expires_at']->toIso8601String(),
                ], $certificates),
                'jit_provisioning' => (bool) $connection?->jit_provisioning,
                'default_role' => $connection->default_role ?? 'VIEWER',
                'email_attribute' => $connection?->email_attribute,
                'name_attribute' => $connection?->name_attribute,
                'last_login_at' => $connection?->last_login_at?->toIso8601String(),
            ],
            'domains' => $domains->map(fn (OrganizationDomain $d): array => $this->presentDomain($d))->all(),
            'max_domains' => SsoDomains::MAX_DOMAINS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDomain(OrganizationDomain $domain): array
    {
        return [
            'id' => $domain->public_id,
            'domain' => $domain->domain,
            'verified' => $domain->isVerified(),
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
            'txt_name' => $domain->domain,
            'txt_value' => $domain->txtRecord(),
        ];
    }

    /**
     * @param  array{is_enabled: bool, enforced: bool, idp_entity_id: string|null, idp_sso_url: string|null, idp_certificate: string|null, jit_provisioning: bool, default_role: string, email_attribute: string|null, name_attribute: string|null}  $data
     */
    public function update(Organization $organization, array $data): SsoConnection
    {
        $connection = $this->sso->connectionOf($organization) ?? new SsoConnection(['organization_id' => $organization->id]);
        $connection->fill($data);
        $changed = array_keys($connection->getDirty());
        $connection->save();

        if ($changed !== []) {
            $values = array_intersect_key($connection->only(self::AUDITED_VALUES), array_flip($changed));
            $this->audit->log(
                AuditAction::SSO_CONNECTION_UPDATED,
                $connection,
                ['changes' => array_values(array_diff($changed, ['organization_id'])), 'values' => $values],
                organizationId: $organization->id,
            );
        }

        return $connection;
    }

    /**
     * Metadatos XML del IdP → entity ID, URL de inicio de sesión y certificados.
     *
     * @return array{idp_entity_id: string, idp_sso_url: string, idp_certificate: string}
     */
    public function parseMetadata(string $xml): array
    {
        try {
            $info = IdPMetadataParser::parseXML($xml);
        } catch (Throwable) {
            throw ValidationException::withMessages(['xml' => 'No pudimos leer los metadatos: revisa que sea el XML completo del proveedor de identidad.']);
        }

        $idp = is_array($info['idp'] ?? null) ? $info['idp'] : [];
        $certificates = $idp['x509certMulti']['signing'] ?? (isset($idp['x509cert']) ? [$idp['x509cert']] : []);
        $certificates = array_slice(array_values(array_filter(array_map('strval', (array) $certificates))), 0, SamlSettings::MAX_CERTIFICATES);

        $entityId = (string) ($idp['entityId'] ?? '');
        $ssoUrl = (string) ($idp['singleSignOnService']['url'] ?? '');
        if ($entityId === '' || $ssoUrl === '' || $certificates === []) {
            throw ValidationException::withMessages(['xml' => 'Los metadatos no traen el entity ID, la URL de inicio de sesión o el certificado de firma.']);
        }

        $pem = implode("\n", array_map(
            fn (string $body): string => "-----BEGIN CERTIFICATE-----\n" . chunk_split(SamlSettings::certificates($body)[0] ?? '', 64, "\n") . '-----END CERTIFICATE-----',
            $certificates,
        ));

        return [
            'idp_entity_id' => $entityId,
            'idp_sso_url' => $ssoUrl,
            'idp_certificate' => $pem,
        ];
    }
}
