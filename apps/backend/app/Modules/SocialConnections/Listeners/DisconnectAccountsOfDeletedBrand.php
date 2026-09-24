<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Listeners;

use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Services\SocialConnectionService;

/**
 * Las cuentas de una marca eliminada se desconectan: se borran sus tokens
 * (no deben quedar credenciales de terceros sin uso) y deja de contar para el
 * límite de cuentas del plan.
 */
class DisconnectAccountsOfDeletedBrand
{
    public function __construct(private readonly SocialConnectionService $connections)
    {
    }

    public function handle(BrandDeleted $event): void
    {
        SocialConnection::query()->withoutGlobalScopes()
            ->where('brand_id', $event->brand->id)
            ->each(fn (SocialConnection $connection) => $this->connections->disconnect($connection));
    }
}
