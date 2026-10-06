<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Modules\Automations\Enums\AutomationRunStatus;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Enums\FlowStepType;
use App\Modules\Automations\Enums\WaitUnit;
use App\Modules\Automations\Flow\AutomationFlow;
use App\Modules\Automations\Flow\ConditionEvaluator;
use App\Modules\Automations\Flow\RunTrace;
use App\Modules\Automations\Jobs\RunAutomation;
use App\Modules\Automations\Models\Automation;
use App\Modules\Automations\Models\AutomationRun;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use Throwable;

/**
 * Motor de reglas: encuentra automatizaciones que coinciden con un disparador
 * y recorre su flujo (acciones, esperas y condiciones), registrando cada
 * ejecución con la traza de sus pasos (docs/05). Una espera deja la ejecución
 * «en espera»; el planificador la retoma desde el paso siguiente.
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
        if (! $this->planAllows($organizationId)) {
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
            RunAutomation::dispatch($id, $context, $brandId);
        }
    }

    /**
     * Despacha una automatización concreta con un disparador externo (webhook
     * entrante o entrada de RSS). Quien llama ya verificó el plan.
     *
     * @param  array<string, mixed>  $context
     */
    public function dispatchDirect(Automation $automation, array $context): void
    {
        if (! $automation->is_enabled) {
            return;
        }

        $context['trigger'] = $automation->trigger->value;
        RunAutomation::dispatch($automation->id, $context, $automation->brand_id);
    }

    /**
     * Recorre el flujo desde el principio con el contexto del disparador.
     *
     * @param  array<string, mixed>  $context
     * @param  int|null  $brandId  Brand del evento (acota a quién avisar)
     */
    public function run(Automation $automation, array $context, ?int $brandId = null): AutomationRun
    {
        $run = AutomationRun::query()->create([
            'organization_id' => $automation->organization_id,
            'automation_id' => $automation->id,
            'brand_id' => $brandId,
            'status' => AutomationRunStatus::RUNNING,
            'trigger' => $automation->trigger->value,
            'context' => $context,
            'steps' => [],
        ]);

        return $this->continue($automation, $run, $automation->definition()->steps(), 0);
    }

    /**
     * Retoma una ejecución cuya espera venció, desde el paso guardado. Si la
     * regla ya no puede seguir (pausada, sin plan o sin ese paso), se cancela.
     */
    public function resume(AutomationRun $run): AutomationRun
    {
        $automation = Automation::query()->withoutGlobalScopes()->find($run->automation_id);

        if ($automation === null || ! $automation->is_enabled) {
            return $this->finish($run, AutomationRunStatus::CANCELLED, 'La automatización está en pausa o se eliminó.');
        }
        if (! $this->planAllows($automation->organization_id)) {
            return $this->finish($run, AutomationRunStatus::CANCELLED, 'Tu plan ya no incluye automatizaciones.');
        }

        // Por identificador: añadir o quitar otros pasos mientras espera no la descoloca.
        $location = $run->resume_step !== null ? $automation->definition()->locate($run->resume_step) : null;
        if ($location === null) {
            return $this->finish($run, AutomationRunStatus::CANCELLED, 'El paso siguiente ya no existe: se editó la automatización.');
        }

        $run->forceFill(['resume_at' => null, 'resume_step' => null]);
        [$steps, $index] = $location;

        return $this->continue($automation, $run, $steps, $index);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function continue(Automation $automation, AutomationRun $run, array $steps, int $start): AutomationRun
    {
        $trace = new RunTrace($run->steps ?? []);
        [$outcome, $error] = $this->walk($steps, $start, $automation, $run, $trace);
        $run->steps = $trace->entries();

        return match ($outcome) {
            AutomationRunStatus::WAITING => $this->save($run, AutomationRunStatus::WAITING, trim('En espera · ' . $trace->summary(), ' ·')),
            AutomationRunStatus::FAILED => $this->finish($run, AutomationRunStatus::FAILED, (string) $error, $automation),
            default => $trace->actionsDone() > 0
                ? $this->finish($run, AutomationRunStatus::SUCCESS, $trace->summary(), $automation)
                : $this->finish($run, AutomationRunStatus::SKIPPED, 'No se cumplieron las condiciones.'),
        };
    }

    /**
     * Ejecuta los pasos de una lista desde $start. Una condición es el último
     * paso de su lista: sigue por su camino «Sí» o «No».
     *
     * @param  list<array<string, mixed>>  $steps
     * @return array{0: AutomationRunStatus, 1: string|null} SUCCESS (terminó), WAITING o FAILED
     */
    private function walk(array $steps, int $start, Automation $automation, AutomationRun $run, RunTrace $trace): array
    {
        $context = $run->context ?? [];

        for ($i = $start, $count = count($steps); $i < $count; $i++) {
            $step = $steps[$i];

            switch (FlowStepType::tryFrom((string) ($step['type'] ?? ''))) {
                case FlowStepType::ACTION:
                    try {
                        $message = $this->executor->execute(
                            ['type' => (string) ($step['action'] ?? ''), 'config' => is_array($step['config'] ?? null) ? $step['config'] : []],
                            $context,
                            $automation,
                            $run->brand_id,
                        );
                    } catch (Throwable $e) {
                        $trace->add($step, AutomationRunStatus::FAILED, $e->getMessage());

                        return [AutomationRunStatus::FAILED, $e->getMessage()];
                    }
                    $trace->add($step, AutomationRunStatus::SUCCESS, $message);
                    break;

                case FlowStepType::WAIT:
                    $next = $steps[$i + 1] ?? null;
                    if ($next === null) {
                        break; // el validador no lo permite: no hay nada que esperar
                    }
                    $unit = WaitUnit::tryFrom((string) ($step['unit'] ?? '')) ?? WaitUnit::HOURS;
                    $amount = max(1, (int) ($step['amount'] ?? 1));
                    $trace->add($step, AutomationRunStatus::WAITING, 'Espera ' . $unit->describe($amount));
                    $run->forceFill([
                        'resume_at' => now()->addSeconds($unit->seconds($amount)),
                        'resume_step' => (string) ($next['id'] ?? ''),
                    ]);

                    return [AutomationRunStatus::WAITING, null];

                case FlowStepType::BRANCH:
                    $matched = ConditionEvaluator::matches($step, $context);
                    $trace->add($step, AutomationRunStatus::SUCCESS, $matched ? 'Sí' : 'No', ['path' => $matched ? 'yes' : 'no']);

                    return $this->walk(AutomationFlow::arm($step, $matched ? 'yes' : 'no'), 0, $automation, $run, $trace);

                default:
                    $trace->add($step, AutomationRunStatus::FAILED, 'Paso desconocido.');

                    return [AutomationRunStatus::FAILED, 'Paso desconocido.'];
            }
        }

        return [AutomationRunStatus::SUCCESS, null];
    }

    /**
     * Cierra la ejecución; las que hicieron algo (o fallaron) cuentan como ejecución de la regla.
     */
    private function finish(AutomationRun $run, AutomationRunStatus $status, string $message, ?Automation $automation = null): AutomationRun
    {
        if ($automation !== null && in_array($status, [AutomationRunStatus::SUCCESS, AutomationRunStatus::FAILED], true)) {
            $automation->update(['last_run_at' => now(), 'run_count' => $automation->run_count + 1]);
        }
        $run->forceFill(['resume_at' => null, 'resume_step' => null]);

        return $this->save($run, $status, $message);
    }

    private function save(AutomationRun $run, AutomationRunStatus $status, string $message): AutomationRun
    {
        $run->forceFill(['status' => $status, 'message' => mb_substr($message, 0, 2000)])->save();

        return $run;
    }

    private function planAllows(int $organizationId): bool
    {
        $organization = Organization::query()->find($organizationId);

        return $organization !== null && $this->entitlements->allows($organization, Entitlement::FEATURE_AUTOMATIONS);
    }
}
