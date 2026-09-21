<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Resultado de responder a una conversación en el proveedor.
 */
final class InboxReplyResult
{
    public function __construct(
        public readonly string $externalId,
    ) {
    }
}
