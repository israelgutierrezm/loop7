<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Events;

use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se eliminó una Organization. Cada módulo retira lo que ya no debe operar sobre
 * ella (marcas y todo lo suyo, API keys, suscripción). Se emite dentro de la
 * transacción del borrado: la limpieza es atómica con él.
 */
class OrganizationDeleted
{
    use Dispatchable;

    public function __construct(public readonly Organization $organization)
    {
    }
}
