<?php

declare(strict_types=1);

namespace App\Modules\Notifications\WhatsApp;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Error de la API de WhatsApp Cloud. El mensaje es apto para mostrar a
 * SUPERADMIN: nunca incluye el token ni el número de destino.
 */
class WhatsAppException extends RuntimeException
{
    /** Token caducado o revocado (OAuthException 190). */
    public const INVALID_TOKEN = 190;

    public static function fromResponse(Response $response): self
    {
        $code = (int) $response->json('error.code', 0);

        if ($code === self::INVALID_TOKEN || $response->status() === 401) {
            return new self('El token de acceso de WhatsApp no es válido o caducó.', self::INVALID_TOKEN);
        }

        $message = (string) $response->json('error.error_data.details', '');
        if ($message === '') {
            $message = (string) $response->json('error.message', 'Error HTTP ' . $response->status());
        }

        return new self(mb_substr('WhatsApp: ' . $message . ($code > 0 ? " (#{$code})" : ''), 0, 300), $code);
    }
}
