<?php

declare(strict_types=1);

namespace App\Modules\Webhooks;

use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Modules\Webhooks\Console\PruneWebhookDeliveriesCommand;
use App\Modules\Webhooks\Listeners\DisableWebhooksOfDeletedOrganization;
use App\Modules\Webhooks\Listeners\SendDomainWebhooks;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * Webhooks salientes firmados (docs/11): las organizaciones suscriben
 * endpoints a eventos de dominio y reciben POST firmados con HMAC.
 */
class WebhooksServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->commands([PruneWebhookDeliveriesCommand::class]);
    }

    protected function bootModule(): void
    {
        Event::subscribe(SendDomainWebhooks::class);
        Event::listen(OrganizationDeleted::class, DisableWebhooksOfDeletedOrganization::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('webhooks:prune')->dailyAt('03:45')->withoutOverlapping();
        });
    }
}
