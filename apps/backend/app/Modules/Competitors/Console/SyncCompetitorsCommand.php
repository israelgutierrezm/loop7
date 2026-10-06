<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Console;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Competitors\Jobs\SyncCompetitorAccount;
use App\Modules\Competitors\Models\CompetitorAccount;
use App\Modules\Competitors\Models\CompetitorPost;
use App\Modules\Competitors\Models\CompetitorSnapshot;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A diario: foto de cada cuenta de la competencia aún sin la de hoy (escalonada
 * para no saturar las APIs) y limpieza de lo antiguo. Las organizaciones cuyo
 * plan ya no incluye la competencia no se consultan (sus datos se conservan).
 */
class SyncCompetitorsCommand extends Command
{
    protected $signature = 'competitors:sync-due';

    protected $description = 'Actualiza las cuentas de la competencia y borra las fotos y publicaciones antiguas.';

    /** Separación entre consultas a las redes. */
    private const STAGGER_SECONDS = 3;

    public const SNAPSHOT_RETENTION_DAYS = 400;

    public const POST_RETENTION_DAYS = 120;

    public function handle(EntitlementsService $entitlements): int
    {
        $allowed = [];
        $queued = 0;

        CompetitorAccount::query()->withoutGlobalScope(OrganizationScope::class)
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', Carbon::today()))
            ->orderBy('id')
            ->select(['id', 'organization_id'])
            ->chunkById(500, function ($accounts) use ($entitlements, &$allowed, &$queued): void {
                foreach ($accounts as $account) {
                    $organizationId = $account->organization_id;
                    $allowed[$organizationId] ??= ($organization = Organization::query()->find($organizationId)) !== null
                        && $entitlements->limit($organization, Entitlement::COMPETITOR_ACCOUNTS_MAX) !== 0;

                    if ($allowed[$organizationId]) {
                        SyncCompetitorAccount::dispatch($account->id)->delay(now()->addSeconds($queued * self::STAGGER_SECONDS));
                        $queued++;
                    }
                }
            });

        $snapshots = CompetitorSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereDate('date', '<', Carbon::today()->subDays(self::SNAPSHOT_RETENTION_DAYS))
            ->delete();
        $posts = CompetitorPost::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('published_at', '<', now()->subDays(self::POST_RETENTION_DAYS))
            ->delete();

        $this->info("Cuentas encoladas: {$queued}. Fotos borradas: {$snapshots}. Publicaciones borradas: {$posts}.");

        return self::SUCCESS;
    }
}
