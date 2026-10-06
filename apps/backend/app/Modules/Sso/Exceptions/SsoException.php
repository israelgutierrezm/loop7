<?php

declare(strict_types=1);

namespace App\Modules\Sso\Exceptions;

use RuntimeException;

/**
 * Inicio de sesión único rechazado. `reason` es un código estable que el SPA
 * traduce (nunca se le dan los detalles técnicos); el mensaje técnico va sólo a
 * la auditoría y a la prueba de configuración de quien administra.
 */
class SsoException extends RuntimeException
{
    public const EXPIRED = 'expired';
    public const NOT_ENABLED = 'not_enabled';
    public const INVALID_RESPONSE = 'invalid_response';
    public const NO_EMAIL = 'no_email';
    public const DOMAIN_NOT_VERIFIED = 'domain_not_verified';
    public const NOT_MEMBER = 'not_member';
    public const SUSPENDED = 'suspended';
    public const SEATS = 'seats';
    public const BLOCKED = 'blocked';
    public const PLATFORM_ADMIN = 'platform_admin';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $reason);
    }
}
