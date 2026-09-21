<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Console;

use App\Modules\Inbox\Services\InboxService;
use Illuminate\Console\Command;

class SyncInboxCommand extends Command
{
    protected $signature = 'inbox:sync-due';

    protected $description = 'Despacha la sincronización del inbox de los destinos conectados.';

    public function handle(InboxService $inbox): int
    {
        $count = $inbox->syncDue();
        $this->info("Jobs de inbox despachados: {$count}");

        return self::SUCCESS;
    }
}
