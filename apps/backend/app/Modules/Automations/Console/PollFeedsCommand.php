<?php

declare(strict_types=1);

namespace App\Modules\Automations\Console;

use App\Modules\Automations\Jobs\PollRssFeed;
use App\Modules\Automations\Services\RssFeedPoller;
use Illuminate\Console\Command;

/**
 * Encola la revisión de los feeds RSS que tocan. Marca cada uno como revisado
 * al encolarlo para no duplicar trabajos si la cola va con retraso.
 */
class PollFeedsCommand extends Command
{
    protected $signature = 'automations:poll-feeds {--limit=200}';

    protected $description = 'Encola la revisión de los feeds RSS de las automatizaciones.';

    public function handle(RssFeedPoller $poller): int
    {
        $due = $poller->due(max(1, (int) $this->option('limit')));

        foreach ($due as $automation) {
            $automation->forceFill(['polled_at' => now()])->save();
            PollRssFeed::dispatch($automation->id);
        }

        $this->info("Feeds encolados: {$due->count()}.");

        return self::SUCCESS;
    }
}
