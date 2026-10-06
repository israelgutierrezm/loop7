<?php

declare(strict_types=1);

namespace App\Modules\Sso\Dns;

/**
 * Registros TXT con el resolvedor del sistema.
 */
final class NativeDnsResolver implements DnsResolver
{
    public function txtRecords(string $host): array
    {
        // dns_get_record avisa (warning) si el nombre no existe: se trata como «sin registros».
        $records = @dns_get_record($host, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            // Un TXT largo puede llegar partido en varias cadenas.
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', array_map('strval', $record['entries']));
            } elseif (isset($record['txt'])) {
                $values[] = (string) $record['txt'];
            }
        }

        return $values;
    }
}
