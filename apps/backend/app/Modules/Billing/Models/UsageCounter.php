<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property string $period
 * @property int $used
 */
class UsageCounter extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'key', 'period', 'used'];

    protected function casts(): array
    {
        return ['used' => 'integer'];
    }
}
