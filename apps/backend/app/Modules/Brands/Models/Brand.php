<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Models\User;
use App\Modules\Brands\Database\Factories\BrandFactory;
use App\Modules\Brands\Enums\BrandStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property BrandStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Brand extends Model
{
    use BelongsToOrganization;
    /** @use HasFactory<BrandFactory> */
    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'website',
        'description',
        'logo_path',
        'primary_color',
        'secondary_color',
        'timezone',
        'status',
        'settings',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'timezone' => 'UTC',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BrandStatus::class,
            'settings' => 'array',
        ];
    }

    /**
     * Usuarios con acceso explícito a esta Brand.
     *
     * @return BelongsToMany<User, $this>
     */
    public function usersWithAccess(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'brand_user_access')
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    /**
     * Concede acceso a la Brand a uno o varios usuarios, rellenando el
     * organization_id del pivot de forma coherente.
     *
     * @param  array<int, int>|int  $userIds
     */
    public function grantAccessTo(array|int $userIds): void
    {
        $ids = collect((array) $userIds)
            ->mapWithKeys(fn (int $id) => [$id => ['organization_id' => $this->organization_id]])
            ->all();

        $this->usersWithAccess()->syncWithoutDetaching($ids);
    }

    protected static function newFactory(): Factory
    {
        return BrandFactory::new();
    }
}
