<?php

declare(strict_types=1);

namespace App\Modules\Automations\Console;

use App\Modules\Automations\Enums\AutomationRunStatus;
use App\Modules\Automations\Jobs\ResumeAutomationRun;
use App\Modules\Automations\Models\AutomationRun;
use Illuminate\Console\Command;

/**
 * Retoma las ejecuciones cuyo paso «Esperar» ya venció (cada minuto).
 */
class ResumeWaitingRunsCommand extends Command
{
    protected $signature = 'automations:resume-waiting';

    protected $description = 'Retoma las ejecuciones de automatizaciones cuya espera venció.';

    private const BATCH = 500;

    public function handle(): int
    {
        $ids = AutomationRun::query()->withoutGlobalScopes()
            ->where('status', AutomationRunStatus::WAITING->value)
            ->where('resume_at', '<=', now())
            ->orderBy('resume_at')
            ->limit(self::BATCH)
            ->pluck('id');

        $resumed = 0;
        foreach ($ids as $id) {
            // Reclamo atómico: si dos planificadores coinciden, sólo uno la retoma.
            $claimed = AutomationRun::query()->withoutGlobalScopes()
                ->whereKey($id)
                ->where('status', AutomationRunStatus::WAITING->value)
                ->update(['status' => AutomationRunStatus::RUNNING->value]);

            if ($claimed === 1) {
                ResumeAutomationRun::dispatch($id);
                $resumed++;
            }
        }

        $this->info("Ejecuciones retomadas: {$resumed}.");

        return self::SUCCESS;
    }
}
