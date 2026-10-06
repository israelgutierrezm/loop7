<?php

declare(strict_types=1);

namespace App\Modules\Automations\Flow;

use App\Modules\Automations\Enums\AutomationRunStatus;
use App\Modules\Automations\Enums\FlowStepType;

/**
 * Traza de una ejecución: qué pasó en cada paso visitado, para mostrarlo sobre
 * el diagrama. Al reanudar tras una espera se continúa la misma traza.
 */
final class RunTrace
{
    /** @var list<array<string, mixed>> */
    private array $entries;

    /** @var list<string> */
    private array $messages = [];

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;

        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) === FlowStepType::ACTION->value && ($entry['status'] ?? null) === AutomationRunStatus::SUCCESS->value) {
                $this->messages[] = (string) ($entry['message'] ?? '');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $extra
     */
    public function add(array $step, AutomationRunStatus $status, string $message, array $extra = []): void
    {
        $this->entries[] = [
            'id' => (string) ($step['id'] ?? ''),
            'type' => (string) ($step['type'] ?? ''),
            'status' => $status->value,
            'message' => mb_substr($message, 0, 500),
            ...$extra,
            'at' => now()->toIso8601String(),
        ];

        if (($step['type'] ?? null) === FlowStepType::ACTION->value && $status === AutomationRunStatus::SUCCESS) {
            $this->messages[] = $message;
        }
    }

    /**
     * Acciones hechas con éxito (en toda la ejecución, también antes de una espera).
     */
    public function actionsDone(): int
    {
        return count($this->messages);
    }

    public function summary(): string
    {
        return implode(' · ', $this->messages);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
