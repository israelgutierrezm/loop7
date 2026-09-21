<?php

declare(strict_types=1);

namespace App\Modules\Automations\Listeners;

use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Services\AutomationEngine;
use App\Modules\Inbox\Events\InboxMessageReceived;

/**
 * Traduce el evento InboxMessageReceived en el disparador de automatización,
 * con el contexto de la conversación y el mensaje entrante.
 */
class RunAutomationsForInboxMessage
{
    public function __construct(private readonly AutomationEngine $engine)
    {
    }

    public function handle(InboxMessageReceived $event): void
    {
        $conversation = $event->conversation;

        $this->engine->dispatchForTrigger(
            AutomationTrigger::INBOX_MESSAGE_RECEIVED,
            $conversation->organization_id,
            $conversation->brand_id,
            [
                'conversation_id' => $conversation->public_id,
                'provider' => $conversation->provider,
                'type' => $conversation->type,
                'participant' => $conversation->participant_name ?? '',
                'text' => $event->message->body,
            ],
        );
    }
}
