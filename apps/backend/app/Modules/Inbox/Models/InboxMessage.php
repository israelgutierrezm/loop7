<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Models\User;
use App\Modules\Inbox\Enums\MessageType;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensaje de una conversación del inbox. Tenant-owned.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $conversation_id
 * @property string|null $external_id
 * @property MessageType $type
 * @property string|null $author_name
 * @property string|null $author_external_id
 * @property string $body
 * @property int|null $via_user_id
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class InboxMessage extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'conversation_id', 'external_id', 'type',
        'author_name', 'author_external_id', 'body', 'via_user_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InboxConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'via_user_id');
    }
}
