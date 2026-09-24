<?php

declare(strict_types=1);

namespace App\Modules\Brands\Events;

use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se eliminó una Brand. Cada módulo retira lo que ya no debe operar sobre ella
 * (publicaciones programadas, cuentas conectadas, automatizaciones).
 */
class BrandDeleted
{
    use Dispatchable;

    public function __construct(public readonly Brand $brand)
    {
    }
}
