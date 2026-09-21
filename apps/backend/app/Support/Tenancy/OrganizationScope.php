<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope que restringe automáticamente cualquier consulta de un modelo
 * tenant-owned a la Organization del contexto actual.
 *
 * Sólo actúa cuando hay una Organization en contexto (requests autenticadas de
 * cliente). En consola/seeders/tests sin contexto no filtra, permitiendo
 * operaciones administrativas; el aislamiento en esos casos se prueba fijando
 * el contexto explícitamente.
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasOrganization()) {
            $builder->where(
                $model->getTable() . '.organization_id',
                $context->organizationId(),
            );
        }
    }
}
