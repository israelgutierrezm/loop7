<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Database\Factories\OrganizationFactory;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property int|null $owner_user_id
 * @property OrganizationStatus $status
 * @property string|null $billing_email
 * @property string $timezone
 * @property array{display_name?: string|null, primary_color?: string|null, logo_path?: string|null}|null $branding
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property list<string>|null $current_roles
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'owner_user_id',
        'status',
        'billing_email',
        'tax_id',
        'country',
        'timezone',
        'locale',
        'settings',
        'trial_ends_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'settings' => 'array',
            'branding' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['status', 'all_brands_access', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /** Suspendida por SUPERADMIN: nadie opera en ella hasta que se reactive. */
    public function isSuspended(): bool
    {
        return $this->status === OrganizationStatus::SUSPENDED;
    }

    /**
     * Organizaciones en las que se trabaja con normalidad (no suspendidas; las
     * eliminadas ya las excluye SoftDeletes). Para los procesos programados.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('status', OrganizationStatus::ACTIVE->value);
    }

    protected static function newFactory(): Factory
    {
        return OrganizationFactory::new();
    }
}
