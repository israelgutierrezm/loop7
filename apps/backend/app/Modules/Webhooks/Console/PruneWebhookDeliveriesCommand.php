<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Webhooks\Enums\DeliveryStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra el registro de entregas antiguas (ya resueltas): el historial sirve
 * para depurar integraciones, no como archivo.
 */
class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:prune {--days=30}';

    protected $description = 'Elimina el registro de entregas de webhooks antiguas.';

    public function handle(): int
    {
        $deleted = DB::table('webhook_deliveries')
            ->where('status', '!=', DeliveryStatus::PENDING->value)
            ->where('created_at', '<', now()->subDays(max(1, (int) $this->option('days'))))
            ->delete();

        $this->info("Entregas de webhooks eliminadas: {$deleted}.");

        return self::SUCCESS;
    }
}
