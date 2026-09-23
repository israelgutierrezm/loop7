<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Destino publicable dentro de una conexión (p.ej. una página de Facebook o
 * una cuenta de Instagram Business). `accessToken` es el token propio del
 * destino si el proveedor lo emite (se guarda cifrado, nunca sale al frontend).
 */
final class RemoteDestination
{
    /**
     * @param  array<string, bool>  $capabilities
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $type,
        public readonly array $capabilities = [],
        public readonly array $metadata = [],
        public readonly ?string $accessToken = null,
    ) {
    }
}
