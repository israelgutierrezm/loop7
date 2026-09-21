<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Organizations\Events\OrganizationCreated;

class StartTrialSubscription
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function handle(OrganizationCreated $event): void
    {
        $this->subscriptions->startTrial($event->organization);
    }
}
