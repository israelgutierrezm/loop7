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
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class ApprovalRequest extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'content_item_id', 'requested_by_user_id',
        'status', 'resolved_by_user_id', 'resolved_at', 'note',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
