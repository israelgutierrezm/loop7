<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

/**
 * Firma HMAC-SHA256 según la especificación abierta Standard Webhooks
 * (standardwebhooks.com), para que el receptor pueda usar sus librerías:
 *
 *   contenido firmado = "{webhook-id}.{webhook-timestamp}.{cuerpo}"
 *   webhook-signature = "v1,<base64(hmac)>" (varias separadas por espacios)
 *
 * La clave HMAC son los bytes del secreto `whsec_<base64>` decodificado.
 */
final class WebhookSigner
{
    /** Antigüedad máxima que un receptor debería aceptar (anti-replay). */
    public const TOLERANCE_SECONDS = 300;

    private const PREFIX = 'whsec_';

    public static function generateSecret(): string
    {
        return self::PREFIX . base64_encode(random_bytes(32));
    }

    /**
     * Cabecera webhook-signature con una firma por secreto vigente.
     *
     * @param  list<string>  $secrets
     */
    public function header(array $secrets, string $messageId, int $timestamp, string $body): string
    {
        return implode(' ', array_map(
            fn (string $secret): string => 'v1,' . $this->sign($secret, $messageId, $timestamp, $body),
            $secrets,
        ));
    }

    public function sign(string $secret, string $messageId, int $timestamp, string $body): string
    {
        return base64_encode(hash_hmac('sha256', "{$messageId}.{$timestamp}.{$body}", $this->key($secret), true));
    }

    /**
     * Verificación tal como la haría un receptor (se usa en las pruebas y
     * documenta el algoritmo).
     */
    public function verify(string $secret, string $messageId, int $timestamp, string $body, string $header, ?int $now = null): bool
    {
        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = $this->sign($secret, $messageId, $timestamp, $body);
        foreach (explode(' ', trim($header)) as $part) {
            [$version, $signature] = array_pad(explode(',', $part, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function key(string $secret): string
    {
        $encoded = str_starts_with($secret, self::PREFIX) ? substr($secret, strlen(self::PREFIX)) : $secret;
        $decoded = base64_decode($encoded, true);

        return $decoded !== false ? $decoded : $encoded;
    }
}
