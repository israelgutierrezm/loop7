<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Services\MetricsSyncService;
use App\Modules\Brands\Models\Brand;
use Illuminate\Console\Command;

/**
 * Genera datos de muestra de analítica para una Brand (sólo desarrollo/demo).
 * Útil para poblar dashboards cuando se usan conexiones sociales simuladas.
 */
class SeedDemoMetricsCommand extends Command
{
    protected $signature = 'analytics:demo {brand : public_id de la Brand} {--days=14}';

    protected $description = 'Genera una serie histórica sintética de métricas para una Brand (demo).';

    public function handle(MetricsSyncService $sync): int
    {
        $brand = Brand::query()->withoutGlobalScopes()->where('public_id', $this->argument('brand'))->first();
        if ($brand === null) {
            $this->error('Brand no encontrada.');

            return self::FAILURE;
        }

        $created = $sync->backfillDemo($brand, (int) $this->option('days'));
        $this->info("Snapshots generados: {$created}");

        return self::SUCCESS;
    }
}
