<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Clave propia de IA de una Organization (BYOK). Cifrada at-rest, nunca visible
 * completa ni devuelta al navegador (docs/07).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $provider
 * @property array<string, string>|null $credentials
 * @property bool $is_active
 */
class OrganizationAiKey extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'provider', 'credentials', 'is_active'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function credentialMap(): array
    {
        return $this->credentials ?? [];
    }
}
