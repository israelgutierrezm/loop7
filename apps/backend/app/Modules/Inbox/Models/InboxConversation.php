<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Models\User;
use App\Modules\Inbox\Enums\ConversationStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversación del inbox. Tenant-owned: aislada por Organization.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property int $social_connection_id
 * @property int|null $social_connection_destination_id
 * @property string $provider
 * @property string $external_id
 * @property string $type
 * @property string|null $participant_name
 * @property string|null $participant_external_id
 * @property ConversationStatus $status
 * @property int|null $assigned_to_user_id
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property string|null $preview
 * @property int $unread_count
 * @property list<string>|null $tags
 */
class InboxConversation extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'brand_id', 'social_connection_id', 'social_connection_destination_id',
        'provider', 'external_id', 'type', 'participant_name', 'participant_external_id',
        'status', 'assigned_to_user_id', 'last_message_at', 'preview', 'unread_count', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
            'tags' => 'array',
        ];
    }

    /**
     * @return HasMany<InboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
