<?php

declare(strict_types=1);

namespace App\Modules\Api\Listeners;

use App\Modules\Api\Models\ApiKey;
use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Support\Tenancy\OrganizationScope;

/**
 * Las API keys de una organización eliminada dejan de autenticar.
 */
class RevokeApiKeysOfDeletedOrganization
{
    public function handle(OrganizationDeleted $event): void
    {
        ApiKey::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $event->organization->id)
            ->update(['is_active' => false]);
    }
}
