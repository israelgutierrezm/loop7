<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Models;

use App\Modules\Brands\Models\Brand;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Competidor de una marca: una empresa con sus cuentas públicas en las redes.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property string $name
 * @property int|null $created_by_user_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CompetitorAccount> $accounts
 */
class Competitor extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = ['organization_id', 'brand_id', 'name', 'created_by_user_id'];

    /**
     * @return HasMany<CompetitorAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(CompetitorAccount::class)->orderBy('provider')->orderBy('id');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
