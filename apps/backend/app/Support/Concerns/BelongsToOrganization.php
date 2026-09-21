<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca un modelo como tenant-owned (pertenece a una Organization).
 *
 * - Aplica el OrganizationScope (aislamiento automático en todas las queries).
 * - Autocompleta organization_id al crear, desde el contexto de tenant.
 *
 * Requisito: la tabla debe tener la columna organization_id.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope());

        static::creating(function ($model): void {
            if (empty($model->organization_id)) {
                $context = app(TenantContext::class);
                if ($context->hasOrganization()) {
                    $model->organization_id = $context->organizationId();
                }
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
