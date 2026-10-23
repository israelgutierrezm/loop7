<?php

declare(strict_types=1);

namespace App\Modules\Reports\Console;

use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Models\ClientReportRun;
use App\Modules\Reports\Services\ReportGenerator;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Retención de informes generados: se borran (con su PDF) a los 400 días, y los
 * que quedaron a medias (un worker caído) pasan a fallidos tras una hora.
 */
class PruneReportsCommand extends Command
{
    public const RETENTION_DAYS = 400;

    protected $signature = 'reports:prune';

    protected $description = 'Borra los informes generados antiguos y cierra los que quedaron a medias';

    public function handle(): int
    {
        $stuck = ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('status', RunStatus::PENDING->value)
            ->where('created_at', '<', now()->subHour())
            ->update(['status' => RunStatus::FAILED->value, 'error' => 'La generación no terminó. Vuelve a intentarlo.']);

        $deleted = 0;
        ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->chunkById(200, function (Collection $runs) use (&$deleted): void {
                $files = $runs->pluck('file_path')->filter()->values()->all();
                ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)->whereIn('id', $runs->pluck('id'))->delete();
                Storage::disk(ReportGenerator::DISK)->delete($files);
                $deleted += $runs->count();
            });

        $this->info("Informes borrados: {$deleted}; a medias cerrados: {$stuck}.");

        return self::SUCCESS;
    }
}
