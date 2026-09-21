<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Destino publicable dentro de una conexión (p.ej. una página de Facebook,
 * una cuenta de Instagram Business, una organización de LinkedIn).
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
    ) {
    }
}
