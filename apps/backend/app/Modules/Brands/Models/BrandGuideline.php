<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $brand_id
 * @property string|null $voice_tone
 * @property array<int, string>|null $value_propositions
 * @property string|null $cta
 * @property array<int, string>|null $hashtags
 * @property array<int, string>|null $preferred_vocabulary
 * @property array<int, string>|null $prohibited_terms
 */
class BrandGuideline extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'brand_id', 'voice_tone', 'value_propositions',
        'cta', 'hashtags', 'preferred_vocabulary', 'prohibited_terms', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'value_propositions' => 'array',
            'hashtags' => 'array',
            'preferred_vocabulary' => 'array',
            'prohibited_terms' => 'array',
        ];
    }
}
