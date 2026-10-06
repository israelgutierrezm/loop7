<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Data;

/**
 * Cuenta propia conectada con la que se consulta a la red (Business Discovery
 * de Instagram, por ejemplo, se pide desde una cuenta profesional propia).
 */
final class CompetitorViewer
{
    /**
     * @param  array<string, string>  $credentials  del proveedor (versión de la API…)
     */
    public function __construct(
        public readonly string $token,
        public readonly string $externalId,
        public readonly array $credentials = [],
    ) {
    }
}
