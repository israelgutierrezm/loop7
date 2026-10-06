<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Requests;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\MembershipService;
use App\Modules\Sso\Http\Requests\Concerns\AuthorizesSsoSettings;
use App\Modules\Sso\Models\OrganizationDomain;
use App\Modules\Sso\Services\SamlSettings;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Conexión SAML de la organización actual. Activarla exige la configuración
 * completa del IdP y al menos un dominio verificado; hacerla obligatoria,
 * tenerla activada.
 */
class UpdateSsoConnectionRequest extends FormRequest
{
    use AuthorizesSsoSettings;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'enforced' => ['required', 'boolean'],
            'idp_entity_id' => ['nullable', 'string', 'max:1024'],
            'idp_sso_url' => [
                'nullable', 'string', 'max:2048', 'url',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Fuera del entorno local, sólo https (el navegador lleva ahí la petición).
                    if (is_string($value) && ! str_starts_with(mb_strtolower($value), 'https://') && ! app()->environment('local')) {
                        $fail('Usa la URL https del proveedor de identidad.');
                    }
                },
            ],
            'idp_certificate' => [
                'nullable', 'string', 'max:30000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && trim($value) !== '' && SamlSettings::inspect($value) === null) {
                        $fail('Pega el certificado X.509 del proveedor de identidad (formato PEM, hasta ' . SamlSettings::MAX_CERTIFICATES . ').');
                    }
                },
            ],
            'jit_provisioning' => ['required', 'boolean'],
            'default_role' => [
                'required', 'string', 'max:64',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $organization = $this->organization();
                    $assignable = $organization !== null && $this->user() !== null
                        ? app(MembershipService::class)->assignableRoles($this->user(), $organization)
                        : [];
                    if ($value === OrganizationRole::OWNER->value || ! in_array($value, $assignable, true)) {
                        $fail('Elige un rol que puedas asignar.');
                    }
                },
            ],
            'email_attribute' => ['nullable', 'string', 'max:255'],
            'name_attribute' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $data = $validator->getData();

                if ($this->boolean('enforced') && ! $this->boolean('is_enabled')) {
                    $validator->errors()->add('enforced', 'Activa el SSO antes de hacerlo obligatorio.');
                }

                if (! $this->boolean('is_enabled')) {
                    return;
                }
                foreach (['idp_entity_id', 'idp_sso_url', 'idp_certificate'] as $field) {
                    if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                        $validator->errors()->add($field, 'Completa la configuración del proveedor de identidad para activar el SSO.');
                    }
                }
                $organization = $this->organization();
                $verified = $organization !== null && OrganizationDomain::query()
                    ->where('organization_id', $organization->id)
                    ->whereNotNull('verified_domain')
                    ->exists();
                if (! $verified) {
                    $validator->errors()->add('is_enabled', 'Verifica al menos un dominio antes de activar el SSO.');
                }
            },
        ];
    }

    /**
     * @return array{is_enabled: bool, enforced: bool, idp_entity_id: string|null, idp_sso_url: string|null, idp_certificate: string|null, jit_provisioning: bool, default_role: string, email_attribute: string|null, name_attribute: string|null}
     */
    public function connection(): array
    {
        $text = fn (string $key): ?string => is_string($this->validated($key)) && trim((string) $this->validated($key)) !== ''
            ? trim((string) $this->validated($key))
            : null;

        return [
            'is_enabled' => $this->boolean('is_enabled'),
            'enforced' => $this->boolean('enforced'),
            'idp_entity_id' => $text('idp_entity_id'),
            'idp_sso_url' => $text('idp_sso_url'),
            'idp_certificate' => $text('idp_certificate'),
            'jit_provisioning' => $this->boolean('jit_provisioning'),
            'default_role' => (string) $this->validated('default_role'),
            'email_attribute' => $text('email_attribute'),
            'name_attribute' => $text('name_attribute'),
        ];
    }

    private function organization(): ?Organization
    {
        return app(TenantContext::class)->organization();
    }
}
