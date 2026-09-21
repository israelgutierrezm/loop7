<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

use Illuminate\Support\Carbon;

/**
 * Conversación (hilo) del inbox, independiente del proveedor. Puede ser un
 * comentario, un mensaje directo o una mención (docs/05 Inbox).
 */
final class InboxThread
{
    /**
     * @param  list<InboxMessageData>  $messages
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $type, // comment | dm | mention
        public readonly string $participantName,
        public readonly string $participantExternalId,
        public readonly Carbon $lastMessageAt,
        public readonly array $messages = [],
    ) {
    }
}
