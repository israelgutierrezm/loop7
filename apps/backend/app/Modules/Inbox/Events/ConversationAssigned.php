<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Events;

use App\Models\User;
use App\Modules\Inbox\Models\InboxConversation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una conversación del inbox se asignó a un miembro del equipo.
 */
class ConversationAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly InboxConversation $conversation,
        public readonly User $assignee,
        public readonly User $assignedBy,
    ) {
    }
}
