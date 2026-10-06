<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Sources;

use App\Modules\Competitors\Exceptions\CompetitorSourceException;
use App\Support\Security\SecretRedactor;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lecturas de las APIs de Meta (Graph y Threads) para la competencia. Devuelve
 * el error tal cual para que cada fuente lo traduzca; el token va en la query,
 * así que un fallo de red nunca propaga el mensaje de cURL (lleva la URL).
 */
final class MetaPublicReader
{
    private const TIMEOUT_SECONDS = 20;

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: int, 1: array<string, mixed>} estado HTTP y JSON
     */
    public static function get(string $url, array $query, string $network): array
    {
        try {
            $response = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS)->get($url, $query);
        } catch (ConnectionException|TransferException $e) {
            Log::warning("Competencia: fallo de conexión con {$network}.", ['error' => SecretRedactor::redact($e->getMessage())]);

            throw new CompetitorSourceException("No se pudo conectar con {$network}. Inténtalo de nuevo en unos minutos.");
        }

        $json = $response->json();

        return [$response->status(), is_array($json) ? $json : []];
    }

    /**
     * Errores comunes de Meta (token, límites); el resto con el mensaje de la red.
     *
     * @param  array<string, mixed>  $error
     */
    public static function failure(array $error, int $status, string $network): CompetitorSourceException
    {
        $code = (int) ($error['code'] ?? 0);

        if ($code === 190 || $code === 102 || $status === 401) {
            return new CompetitorSourceException("La cuenta de {$network} conectada perdió el acceso: reconéctala en Redes sociales.");
        }
        if (in_array($code, [4, 17, 32, 613], true) || $status === 429) {
            return new CompetitorSourceException("{$network} limitó las consultas por ahora; se reintentará más tarde.");
        }

        $message = trim((string) ($error['message'] ?? ''));

        return new CompetitorSourceException(mb_substr("{$network} no permitió leer la cuenta" . ($message !== '' ? ": {$message}" : '.'), 0, 300));
    }
}
