<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

use Illuminate\Support\Carbon;

/**
 * Mensaje de una conversación tal como lo entrega el proveedor, independiente
 * de la red social.
 */
final class InboxMessageData
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $authorName,
        public readonly string $authorExternalId,
        public readonly string $body,
        public readonly string $direction, // inbound | outbound
        public readonly Carbon $sentAt,
    ) {
    }
}
