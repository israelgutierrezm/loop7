<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\SubscriptionService;
use Illuminate\Console\Command;

class SyncSubscriptionsCommand extends Command
{
    protected $signature = 'billing:sync-subscriptions';

    protected $description = 'Aplica las transiciones por tiempo de las suscripciones (aviso de fin de prueba, trial vencido, fin de periodo, gracia, suspensión).';

    public function handle(SubscriptionService $subscriptions): int
    {
        $counts = $subscriptions->syncDue();
        $this->info(sprintf(
            'Avisos de fin de prueba: %d · Expiradas: %d · Canceladas: %d · En gracia: %d · Suspendidas: %d',
            $counts['trial_reminders'],
            $counts['expired'],
            $counts['cancelled'],
            $counts['grace'],
            $counts['suspended'],
        ));

        return self::SUCCESS;
    }
}
