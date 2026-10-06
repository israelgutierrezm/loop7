<?php

declare(strict_types=1);

namespace App\Modules\Sso\Data;

/**
 * Persona que el IdP autenticó (de una respuesta SAML ya validada).
 */
final class SamlIdentity
{
    /**
     * @param  array<string, string>  $attributes  nombre → primer valor (para la prueba de conexión)
     */
    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly string $nameId,
        public readonly array $attributes,
    ) {
    }
}
