<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Publicación reciente de una cuenta de la competencia con sus interacciones
 * públicas (lo que la red permite leer por API).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $competitor_account_id
 * @property string $external_id
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property string|null $type
 * @property string|null $caption
 * @property string|null $permalink
 * @property string|null $thumbnail_url
 * @property int|null $likes
 * @property int|null $comments
 * @property int|null $views
 */
class CompetitorPost extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'competitor_account_id', 'external_id', 'published_at', 'type', 'caption',
        'permalink', 'thumbnail_url', 'likes', 'comments', 'views',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'likes' => 'integer',
            'comments' => 'integer',
            'views' => 'integer',
        ];
    }

    /**
     * «Me gusta» + comentarios (lo comparable entre cuentas; null si la red no lo da).
     */
    public function engagement(): ?int
    {
        if ($this->likes === null && $this->comments === null) {
            return null;
        }

        return (int) $this->likes + (int) $this->comments;
    }
}
