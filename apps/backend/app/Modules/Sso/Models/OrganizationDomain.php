<?php

declare(strict_types=1);

namespace App\Modules\Sso\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Dominio de correo que una organización demuestra que es suyo (registro TXT).
 * Sólo los verificados sirven para el inicio de sesión único.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property string $domain
 * @property string $verification_token
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property string|null $verified_domain
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 */
class OrganizationDomain extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    public const TXT_PREFIX = 'loop7-verification=';

    protected $fillable = ['organization_id', 'domain', 'verification_token', 'verified_at', 'last_checked_at'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Valor del registro TXT que hay que publicar en el DNS del dominio. */
    public function txtRecord(): string
    {
        return self::TXT_PREFIX . $this->verification_token;
    }
}
