<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * Evento de webhook normalizado, independiente de la pasarela.
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data = [],
    ) {
    }
}
