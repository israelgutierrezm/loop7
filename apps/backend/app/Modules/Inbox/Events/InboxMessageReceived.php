<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Events;

use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Inbox\Models\InboxMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se emite cuando llega un nuevo mensaje entrante al inbox. Permite a Automations
 * reaccionar (auto-respuesta, etiquetado, notificación) sin acoplar el módulo.
 */
class InboxMessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly InboxConversation $conversation,
        public readonly InboxMessage $message,
    ) {
    }
}
