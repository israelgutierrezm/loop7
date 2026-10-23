<?php

declare(strict_types=1);

namespace App\Modules\Reports\Models;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Reports\Enums\ReportFrequency;
use App\Modules\Reports\Enums\ReportPeriod;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Informe para clientes de una marca: qué secciones lleva, qué periodo cubre, a
 * quién se envía y cada cuánto.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property string $name
 * @property ReportPeriod $period
 * @property list<string> $sections
 * @property string|null $intro
 * @property list<string> $recipients
 * @property ReportFrequency|null $frequency
 * @property int|null $send_day
 * @property int $send_hour
 * @property \Carbon\CarbonInterface|null $next_send_at
 * @property \Illuminate\Support\Carbon|null $last_sent_at
 * @property int|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Brand $brand
 * @property-read User|null $creator
 */
class ClientReport extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'brand_id', 'name', 'period', 'sections', 'intro', 'recipients',
        'frequency', 'send_day', 'send_hour', 'next_send_at', 'last_sent_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period' => ReportPeriod::class,
            'sections' => 'array',
            'recipients' => 'array',
            'frequency' => ReportFrequency::class,
            'send_day' => 'integer',
            'send_hour' => 'integer',
            'next_send_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<ClientReportRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ClientReportRun::class);
    }

    public function hasSection(string $section): bool
    {
        return in_array($section, $this->sections, true);
    }
}
