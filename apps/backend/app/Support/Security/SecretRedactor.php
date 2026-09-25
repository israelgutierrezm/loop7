<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Elimina secretos de textos que acaban en logs, auditoría o errores visibles
 * (p. ej. el mensaje de cURL de un fallo de red incluye la URL con su query).
 */
final class SecretRedactor
{
    private const QUERY_SECRETS = '/\b(access_token|refresh_token|client_secret|input_token|fb_exchange_token|appsecret_proof|api_key|apikey|password)=([^&\s"\'<>]+)/i';

    private const BEARER = '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i';

    public static function redact(string $text): string
    {
        $text = (string) preg_replace(self::QUERY_SECRETS, '$1=[REDACTED]', $text);

        return (string) preg_replace(self::BEARER, 'Bearer [REDACTED]', $text);
    }
}
