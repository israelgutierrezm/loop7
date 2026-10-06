<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Jobs;

use App\Modules\Competitors\Models\CompetitorAccount;
use App\Modules\Competitors\Services\CompetitorSync;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Foto diaria de una cuenta de la competencia (cola `analytics`). Sólo lee: un
 * reintento no duplica nada.
 */
class SyncCompetitorAccount implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public int $timeout = 60;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue('analytics');
    }

    public function handle(CompetitorSync $sync): void
    {
        $account = CompetitorAccount::query()->withoutGlobalScope(OrganizationScope::class)->find($this->accountId);

        if ($account !== null) {
            $sync->sync($account);
        }
    }
}
