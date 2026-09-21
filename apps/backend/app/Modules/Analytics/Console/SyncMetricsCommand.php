<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Services\MetricsSyncService;
use Illuminate\Console\Command;

class SyncMetricsCommand extends Command
{
    protected $signature = 'analytics:sync-due';

    protected $description = 'Despacha la sincronización de métricas de cuentas y publicaciones.';

    public function handle(MetricsSyncService $sync): int
    {
        $count = $sync->syncDue();
        $this->info("Jobs de sincronización despachados: {$count}");

        return self::SUCCESS;
    }
}
