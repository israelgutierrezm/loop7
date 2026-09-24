<?php

declare(strict_types=1);

namespace App\Modules\Automations\Jobs;

use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Services\AutomationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ejecuta una automatización concreta con el contexto de un disparador. Se aísla
 * en un job para que un fallo no afecte a las demás reglas ni al flujo que la
 * disparó (docs/05).
 */
class RunAutomation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $automationId,
        public readonly array $context,
        public readonly ?int $brandId = null,
    ) {
        $this->onQueue('automations');
    }

    public function handle(AutomationEngine $engine): void
    {
        $automation = Automation::query()->withoutGlobalScopes()->find($this->automationId);

        if ($automation !== null && $automation->is_enabled) {
            $engine->run($automation, $this->context, $this->brandId);
        }
    }
}
