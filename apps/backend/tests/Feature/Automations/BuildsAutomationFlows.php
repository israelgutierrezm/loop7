<?php

declare(strict_types=1);

namespace Tests\Feature\Automations;

use App\Modules\Automations\Flow\AutomationFlow;

/**
 * Flujos del editor visual a partir de acciones (y condiciones en Y): las
 * acciones quedan como pasos `a1`, `a2`… y las condiciones en el paso `b1`.
 */
trait BuildsAutomationFlows
{
    /**
     * @param  list<array{type: string, config?: array<string, mixed>}>  $actions
     * @param  list<array{field: string, operator: string, value?: string}>  $conditions
     * @return array{steps: list<array<string, mixed>>}
     */
    protected function flow(array $actions, array $conditions = []): array
    {
        return AutomationFlow::fromLegacy($conditions, $actions)->toArray();
    }
}
