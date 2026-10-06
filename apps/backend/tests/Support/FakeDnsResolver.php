<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Sso\Dns\DnsResolver;

/**
 * DNS de prueba: registros TXT fijados por el test.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    public function txtRecords(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}
