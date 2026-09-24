<?php

declare(strict_types=1);

namespace App\Modules\Billing\Events;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Transición del ciclo de vida de una suscripción. `action` es la acción de
 * auditoría correspondiente (AuditAction::SUBSCRIPTION_*).
 */
class SubscriptionChanged
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $action,
        public readonly array $properties = [],
    ) {
    }
}
