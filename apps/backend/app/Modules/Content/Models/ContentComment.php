<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $content_item_id
 * @property int|null $user_id
 * @property string $body
 */
class ContentComment extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = ['organization_id', 'content_item_id', 'user_id', 'body'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
