<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Organizations\Events\OrganizationDeleted;

/**
 * La suscripción de una organización eliminada se cancela en el acto. Las que
 * tienen cobro automático en la pasarela deben cancelarse antes (el borrado se
 * rechaza mientras siga activo; ver SubscriptionService::hasAutomaticCharge).
 */
class CancelSubscriptionOfDeletedOrganization
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function handle(OrganizationDeleted $event): void
    {
        $subscription = $this->subscriptions->find($event->organization);
        if ($subscription === null
            || in_array($subscription->status, [SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED], true)) {
            return;
        }

        $this->subscriptions->cancel($event->organization, atPeriodEnd: false);
    }
}
