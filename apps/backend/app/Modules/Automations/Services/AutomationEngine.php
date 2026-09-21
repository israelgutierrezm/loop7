<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Enums\ConditionOperator;
use App\Modules\Automations\Jobs\RunAutomation;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Models\AutomationRun;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use Throwable;

/**
 * Motor de reglas: encuentra automatizaciones que coinciden con un disparador,
 * evalúa condiciones y ejecuta acciones, registrando cada ejecución (docs/05).
 */
class AutomationEngine
{
    public function __construct(
        private readonly ActionExecutor $executor,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    /**
     * Despacha (a la cola) las automatizaciones que coinciden con el disparador.
     *
     * @param  array<string, mixed>  $context
     */
    public function dispatchForTrigger(
        AutomationTrigger $trigger,
        int $organizationId,
        ?int $brandId,
        array $context,
    ): void {
        $context['trigger'] = $trigger->value;

        // Respeta el plan: si la Organization ya no incluye automatizaciones, no dispara.
        $organization = Organization::query()->find($organizationId);
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_AUTOMATIONS)) {
            return;
        }

        $automations = Automation::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('trigger', $trigger->value)
            ->where('is_enabled', true)
            ->where(function ($q) use ($brandId): void {
                $q->whereNull('brand_id');
                if ($brandId !== null) {
                    $q->orWhere('brand_id', $brandId);
                }
            })
            ->pluck('id');

        foreach ($automations as $id) {
            RunAutomation::dispatch($id, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function run(Automation $automation, array $context): AutomationRun
    {
        if (! $this->evaluateConditions($automation->conditions ?? [], $context)) {
            return $this->record($automation, 'skipped', 'No cumple las condiciones.', $context);
        }

        $messages = [];
        try {
            foreach ($automation->actions as $action) {
                $messages[] = $this->executor->execute($action, $context);
            }
        } catch (Throwable $e) {
            $this->touch($automation);

            return $this->record($automation, 'failed', $e->getMessage(), $context);
        }

        $this->touch($automation);

        return $this->record($automation, 'success', implode(' · ', $messages), $context);
    }

    /**
     * @param  list<array{field?: string, operator?: string, value?: string}>  $conditions
     * @param  array<string, mixed>  $context
     */
    public function evaluateConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            $operator = ConditionOperator::tryFrom($condition['operator'] ?? '');
            if ($operator === null) {
                continue;
            }
            $actual = (string) ($context[$condition['field'] ?? ''] ?? '');
            if (! $operator->matches($actual, (string) ($condition['value'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private function touch(Automation $automation): void
    {
        $automation->update(['last_run_at' => now(), 'run_count' => $automation->run_count + 1]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(Automation $automation, string $status, string $message, array $context): AutomationRun
    {
        return AutomationRun::query()->create([
            'organization_id' => $automation->organization_id,
            'automation_id' => $automation->id,
            'status' => $status,
            'trigger' => $automation->trigger->value,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
