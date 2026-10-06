<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Modules\Automations\Enums\AutomationActionType;
use App\Modules\Automations\Enums\AutomationRunStatus;
use App\Modules\Automations\Enums\FlowStepType;
use App\Modules\Automations\Enums\NotifyAudience;
use App\Modules\Automations\Enums\WaitUnit;
use App\Modules\Automations\Flow\AutomationFlow;
use App\Modules\Automations\Flow\ConditionEvaluator;
use App\Modules\Automations\Support\TemplateRenderer;

/**
 * «Probar» del editor: recorre el flujo con datos de ejemplo SIN ejecutar nada
 * (ni avisos, ni webhooks, ni borradores) y devuelve por qué camino iría y qué
 * haría cada acción con las variables ya sustituidas.
 */
final class FlowSimulator
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{status: string, steps: list<array<string, mixed>>}
     */
    public function simulate(AutomationFlow $flow, array $context): array
    {
        $trace = [];
        $actions = $this->walk($flow->steps(), $context, $trace);

        return [
            'status' => ($actions > 0 ? AutomationRunStatus::SUCCESS : AutomationRunStatus::SKIPPED)->value,
            'steps' => $trace,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $trace
     * @return int acciones que se harían
     */
    private function walk(array $steps, array $context, array &$trace): int
    {
        $actions = 0;

        foreach ($steps as $step) {
            $entry = ['id' => (string) ($step['id'] ?? ''), 'type' => (string) ($step['type'] ?? '')];

            switch (FlowStepType::tryFrom($entry['type'])) {
                case FlowStepType::ACTION:
                    $type = AutomationActionType::tryFrom((string) ($step['action'] ?? ''));
                    $trace[] = [...$entry, 'status' => AutomationRunStatus::SUCCESS->value, 'message' => $type !== null ? $type->label() : '—', 'preview' => $this->preview($type, $step, $context)];
                    $actions++;
                    break;

                case FlowStepType::WAIT:
                    $unit = WaitUnit::tryFrom((string) ($step['unit'] ?? '')) ?? WaitUnit::HOURS;
                    $trace[] = [...$entry, 'status' => AutomationRunStatus::WAITING->value, 'message' => 'Esperaría ' . $unit->describe(max(1, (int) ($step['amount'] ?? 1)))];
                    break;

                case FlowStepType::BRANCH:
                    $matched = ConditionEvaluator::matches($step, $context);
                    $trace[] = [...$entry, 'status' => AutomationRunStatus::SUCCESS->value, 'message' => $matched ? 'Sí' : 'No', 'path' => $matched ? 'yes' : 'no'];

                    return $actions + $this->walk(AutomationFlow::arm($step, $matched ? 'yes' : 'no'), $context, $trace);
            }
        }

        return $actions;
    }

    /**
     * Lo que haría la acción, con las variables sustituidas.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $context
     * @return list<array{label: string, value: string}>
     */
    private function preview(?AutomationActionType $type, array $step, array $context): array
    {
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $text = fn (string $key): string => TemplateRenderer::render(is_scalar($config[$key] ?? null) ? (string) $config[$key] : '', $context);
        $row = fn (string $label, string $value): array => ['label' => $label, 'value' => $value];

        return match ($type) {
            AutomationActionType::NOTIFY => [
                $row('Mensaje', $text('message')),
                $row('Avisar a', (NotifyAudience::tryFrom((string) ($config['audience'] ?? '')) ?? NotifyAudience::MANAGERS)->label()),
            ],
            AutomationActionType::WEBHOOK => [$row('POST a', (string) ($config['url'] ?? ''))],
            AutomationActionType::CREATE_DRAFT => $text('body') !== ''
                ? [$row('Título', $text('title')), $row('Texto', $text('body'))]
                : [$row('Título', $text('title'))],
            AutomationActionType::INBOX_REPLY => [$row('Respuesta', $text('message'))],
            AutomationActionType::INBOX_TAG => [$row('Etiqueta', (string) ($config['tag'] ?? ''))],
            null => [],
        };
    }
}
