<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Foto diaria de las métricas públicas de una cuenta de la competencia.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $competitor_account_id
 * @property \Illuminate\Support\Carbon $date
 * @property int|null $followers
 * @property int|null $posts_count
 * @property array<string, int>|null $weekly
 */
class CompetitorSnapshot extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'competitor_account_id', 'date', 'followers', 'posts_count', 'weekly'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'followers' => 'integer',
            'posts_count' => 'integer',
            'weekly' => 'array',
        ];
    }
}
