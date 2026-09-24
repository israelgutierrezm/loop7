<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Valor de un entitlement fijado por SUPERADMIN para una Organization concreta.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $entitlement_key
 * @property string $value
 * @property string|null $note
 */
class OrganizationEntitlementOverride extends Model
{
    protected $fillable = ['organization_id', 'entitlement_key', 'value', 'note'];
}
