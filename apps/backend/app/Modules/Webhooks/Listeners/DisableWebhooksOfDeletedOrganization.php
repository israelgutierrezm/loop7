<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Listeners;

use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Support\Tenancy\OrganizationScope;

/**
 * Una organización eliminada deja de enviar webhooks (sus entregas pendientes
 * se descartan al intentar enviarlas).
 */
class DisableWebhooksOfDeletedOrganization
{
    public function handle(OrganizationDeleted $event): void
    {
        WebhookEndpoint::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $event->organization->id)
            ->update(['is_active' => false, 'disabled_at' => now()]);
    }
}
