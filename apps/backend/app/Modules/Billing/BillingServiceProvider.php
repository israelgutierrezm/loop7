<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Modules\Billing\Console\SyncSubscriptionsCommand;
use App\Modules\Billing\Listeners\CancelSubscriptionOfDeletedOrganization;
use App\Modules\Billing\Listeners\StartTrialSubscription;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Events\OrganizationCreated;
use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Memoiza entitlements por request.
        $this->app->scoped(EntitlementsService::class);
        $this->commands([SyncSubscriptionsCommand::class]);
    }

    protected function bootModule(): void
    {
        Event::listen(OrganizationCreated::class, StartTrialSubscription::class);
        Event::listen(OrganizationDeleted::class, CancelSubscriptionOfDeletedOrganization::class);

        // Cada hora: vence trials, cierra cancelaciones programadas, gracia y suspensión.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('billing:sync-subscriptions')->hourly()->withoutOverlapping();
        });
    }
}
