<?php

declare(strict_types=1);

namespace App\Modules\Billing\Events;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El periodo de prueba termina pronto (se emite una sola vez por prueba).
 */
class TrialEndingSoon
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription)
    {
    }
}
