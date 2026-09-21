<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $add_on_id
 * @property int $quantity
 * @property-read AddOn $addOn
 */
class OrganizationAddOn extends Model
{
    use BelongsToOrganization;

    protected $table = 'organization_add_ons';

    protected $fillable = ['organization_id', 'add_on_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<AddOn, $this>
     */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }
}
