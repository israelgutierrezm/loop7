<?php

declare(strict_types=1);

namespace App\Modules\Automations\Jobs;

use App\Modules\Automations\Enums\AutomationRunStatus;
use App\Modules\Automations\Models\AutomationRun;
use App\Modules\Automations\Services\AutomationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retoma una ejecución cuya espera venció (la reclama antes el planificador).
 * Un solo intento: repetir podría hacer dos veces las acciones ya hechas.
 */
class ResumeAutomationRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('automations');
    }

    public function handle(AutomationEngine $engine): void
    {
        $run = AutomationRun::query()->withoutGlobalScopes()->find($this->runId);

        if ($run !== null && $run->status === AutomationRunStatus::RUNNING) {
            $engine->resume($run);
        }
    }
}
