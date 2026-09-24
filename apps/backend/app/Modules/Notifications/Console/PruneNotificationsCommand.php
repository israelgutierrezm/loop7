<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retención de avisos: los leídos se conservan 90 días y los no leídos 180.
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune {--read-days=90} {--unread-days=180}';

    protected $description = 'Elimina los avisos in-app antiguos.';

    public function handle(): int
    {
        $read = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(max(1, (int) $this->option('read-days'))))
            ->delete();

        $unread = DB::table('notifications')
            ->whereNull('read_at')
            ->where('created_at', '<', now()->subDays(max(1, (int) $this->option('unread-days'))))
            ->delete();

        $this->info("Avisos eliminados: {$read} leídos · {$unread} sin leer.");

        return self::SUCCESS;
    }
}
