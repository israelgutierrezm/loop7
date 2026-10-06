<?php

declare(strict_types=1);

namespace App\Modules\Sso\Dns;

/**
 * Consulta de registros TXT (contrato propio para poder simularlo en pruebas).
 */
interface DnsResolver
{
    /**
     * @return list<string>
     */
    public function txtRecords(string $host): array;
}
