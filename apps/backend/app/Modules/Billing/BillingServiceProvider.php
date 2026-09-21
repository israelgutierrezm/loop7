<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Modules\Billing\Listeners\StartTrialSubscription;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Events\OrganizationCreated;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Memoiza entitlements por request.
        $this->app->scoped(EntitlementsService::class);
    }

    protected function bootModule(): void
    {
        Event::listen(OrganizationCreated::class, StartTrialSubscription::class);
    }
}
