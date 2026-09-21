<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Events;

use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se emite al crear una Organization. Permite a otros módulos (p.ej. Billing)
 * reaccionar sin acoplar el módulo Organizations a ellos.
 */
class OrganizationCreated
{
    use Dispatchable;

    public function __construct(public readonly Organization $organization)
    {
    }
}
