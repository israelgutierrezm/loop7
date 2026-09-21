<?php

declare(strict_types=1);

namespace App\Modules\Content\Console;

use App\Modules\Content\Services\PublishingService;
use Illuminate\Console\Command;

class PublishDueContent extends Command
{
    protected $signature = 'content:publish-due';

    protected $description = 'Despacha los targets de publicación programados que ya vencieron.';

    public function handle(PublishingService $publishing): int
    {
        $count = $publishing->dispatchDue();
        $this->info("Targets despachados: {$count}");

        return self::SUCCESS;
    }
}
