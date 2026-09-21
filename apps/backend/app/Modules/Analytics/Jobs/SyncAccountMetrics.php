<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Services\MetricsSyncService;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Sincroniza las métricas de cuenta de un destino hacia el snapshot del día.
 * Reintentable; el proveedor sin configurar se omite sin fallar.
 */
class SyncAccountMetrics implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $destinationId)
    {
        $this->onQueue('analytics');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-account-' . $this->destinationId))->dontRelease()];
    }

    public function handle(MetricsSyncService $sync): void
    {
        $destination = SocialConnectionDestination::query()->withoutGlobalScopes()->find($this->destinationId);

        if ($destination !== null) {
            $sync->syncAccount($destination);
        }
    }
}
