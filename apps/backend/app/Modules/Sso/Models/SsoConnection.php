<?php

declare(strict_types=1);

namespace App\Modules\Sso\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Conexión SAML 2.0 de una organización con su proveedor de identidad. El
 * certificado del IdP es público (sirve para comprobar sus firmas).
 *
 * @property int $id
 * @property int $organization_id
 * @property bool $is_enabled
 * @property bool $enforced
 * @property string|null $idp_entity_id
 * @property string|null $idp_sso_url
 * @property string|null $idp_certificate
 * @property bool $jit_provisioning
 * @property string $default_role
 * @property string|null $email_attribute
 * @property string|null $name_attribute
 * @property \Illuminate\Support\Carbon|null $last_login_at
 */
class SsoConnection extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'is_enabled', 'enforced', 'idp_entity_id', 'idp_sso_url', 'idp_certificate',
        'jit_provisioning', 'default_role', 'email_attribute', 'name_attribute', 'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'enforced' => 'boolean',
            'jit_provisioning' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** ¿Tiene lo necesario para iniciar sesión con el IdP? */
    public function isConfigured(): bool
    {
        return $this->idp_entity_id !== null && $this->idp_entity_id !== ''
            && $this->idp_sso_url !== null && $this->idp_sso_url !== ''
            && $this->idp_certificate !== null && $this->idp_certificate !== '';
    }
}
