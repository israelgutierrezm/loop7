<?php

declare(strict_types=1);

namespace App\Support\Security;

use InvalidArgumentException;

/**
 * Validación anti-SSRF para URLs de salida que configuran los clientes (p. ej.
 * webhooks de automatizaciones): sólo http(s) hacia servidores públicos, sin
 * credenciales embebidas ni hosts/IPs internos, privados o reservados.
 */
final class OutboundUrl
{
    private const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa'];

    /**
     * Comprueba la URL y devuelve su destino ya resuelto, para fijar esa IP en
     * la conexión (evita que un DNS "rebinding" cambie el destino después).
     *
     * @return array{host: string, port: int, ip: string}
     *
     * @throws InvalidArgumentException con un mensaje apto para el usuario
     */
    public static function resolve(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('La URL debe empezar por https:// (o http://) e incluir un dominio.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('La URL no puede incluir usuario ni contraseña.');
        }

        if ($host === 'localhost' || self::hasInternalSuffix($host)) {
            throw new InvalidArgumentException('La URL debe apuntar a un servidor público de Internet.');
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : self::lookup($host);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || self::isSharedAddressSpace($ip)
        ) {
            throw new InvalidArgumentException('La URL debe apuntar a un servidor público de Internet.');
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'ip' => $ip,
        ];
    }

    /**
     * Opciones de Guzzle que fuerzan la conexión a la IP validada (IPv4) y
     * no siguen redirecciones (podrían llevar a un destino interno).
     *
     * @param  array{host: string, port: int, ip: string}  $target
     * @return array<string, mixed>
     */
    public static function pinnedOptions(array $target): array
    {
        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ];
    }

    private static function lookup(string $host): string
    {
        $ips = gethostbynamel($host);

        if ($ips === false || $ips === []) {
            throw new InvalidArgumentException("No se pudo resolver el dominio {$host}.");
        }

        // Todas deben ser públicas: si alguna no lo es, se rechaza.
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || self::isSharedAddressSpace($ip)
            ) {
                throw new InvalidArgumentException('La URL debe apuntar a un servidor público de Internet.');
            }
        }

        return $ips[0];
    }

    private static function hasInternalSuffix(string $host): bool
    {
        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 100.64.0.0/10 (CGNAT, RFC 6598): no la cubren los filtros de PHP.
     */
    private static function isSharedAddressSpace(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
    }
}
