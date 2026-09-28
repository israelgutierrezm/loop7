<?php

declare(strict_types=1);

namespace App\Modules\Automations\Jobs;

use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Services\RssFeedPoller;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Revisa el feed de una automatización RSS. Un solo intento: si falla, el
 * error queda en su estado y se vuelve a probar en el siguiente sondeo.
 */
class PollRssFeed implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $automationId)
    {
        $this->onQueue('automations');
    }

    public function handle(RssFeedPoller $poller, EntitlementsService $entitlements): void
    {
        $automation = Automation::query()->withoutGlobalScope(OrganizationScope::class)->find($this->automationId);
        if ($automation === null || ! $automation->is_enabled || $automation->trigger !== AutomationTrigger::RSS_ITEM_PUBLISHED) {
            return;
        }

        // Si el plan ya no incluye automatizaciones, no se lee el feed.
        $organization = Organization::query()->find($automation->organization_id);
        if ($organization === null || ! $entitlements->allows($organization, Entitlement::FEATURE_AUTOMATIONS)) {
            return;
        }

        $poller->poll($automation);
    }
}
