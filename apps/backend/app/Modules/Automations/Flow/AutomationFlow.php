<?php

declare(strict_types=1);

namespace App\Modules\Automations\Flow;

use App\Modules\Automations\Enums\FlowStepType;

/**
 * Flujo de una automatización (docs/05): una lista de pasos. Una condición
 * termina su lista y continúa por «Sí» o por «No», cada uno con sus pasos (un
 * árbol, sin uniones: no hay pasos después de una condición).
 *
 * Paso: {id, type: action, action, config} | {id, type: wait, amount, unit} |
 * {id, type: branch, match, conditions, yes, no}.
 */
final class AutomationFlow
{
    public const MAX_STEPS = 30;

    public const MAX_ACTIONS = 10;

    public const MAX_WAITS = 5;

    public const MAX_WAIT_DAYS = 30;

    /** Condiciones anidadas como máximo. */
    public const MAX_DEPTH = 4;

    public const MAX_CONDITIONS = 10;

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function __construct(private readonly array $steps)
    {
    }

    /**
     * @param  array<string, mixed>|null  $data  {steps: [...]}
     */
    public static function fromArray(?array $data): self
    {
        $steps = $data['steps'] ?? [];

        return new self(is_array($steps) ? array_values(array_filter($steps, 'is_array')) : []);
    }

    /**
     * @param  list<array<string, mixed>>  $steps  ya validados
     */
    public static function fromSteps(array $steps): self
    {
        return new self($steps);
    }

    /**
     * Formato anterior (condiciones en Y + lista de acciones): una condición con
     * las acciones en «Sí», o sólo las acciones si no había condiciones.
     *
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<int, array<string, mixed>>  $actions
     */
    public static function fromLegacy(array $conditions, array $actions): self
    {
        $steps = [];
        foreach (array_values($actions) as $i => $action) {
            $steps[] = [
                'id' => 'a' . ($i + 1),
                'type' => FlowStepType::ACTION->value,
                'action' => (string) ($action['type'] ?? ''),
                'config' => is_array($action['config'] ?? null) ? $action['config'] : [],
            ];
        }

        if ($conditions === []) {
            return new self($steps);
        }

        return new self([[
            'id' => 'b1',
            'type' => FlowStepType::BRANCH->value,
            'match' => 'all',
            'conditions' => array_values($conditions),
            'yes' => $steps,
            'no' => [],
        ]]);
    }

    /**
     * @return array{steps: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['steps' => $this->steps];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Lista que contiene el paso y su posición: desde ahí se reanuda tras una espera.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}|null
     */
    public function locate(string $id): ?array
    {
        return self::search($this->steps, $id);
    }

    /**
     * Todas las acciones, en el orden del flujo.
     *
     * @return list<array<string, mixed>>
     */
    public function actions(): array
    {
        return array_values(array_filter($this->all(), fn (array $s): bool => ($s['type'] ?? null) === FlowStepType::ACTION->value));
    }

    /**
     * Todos los pasos, aplanados (en profundidad).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return self::flatten($this->steps);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{0: list<array<string, mixed>>, 1: int}|null
     */
    private static function search(array $steps, string $id): ?array
    {
        foreach ($steps as $index => $step) {
            if (($step['id'] ?? null) === $id) {
                return [$steps, $index];
            }
            if (($step['type'] ?? null) === FlowStepType::BRANCH->value) {
                foreach (['yes', 'no'] as $arm) {
                    $found = self::search(self::arm($step, $arm), $id);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function flatten(array $steps): array
    {
        $all = [];
        foreach ($steps as $step) {
            $all[] = $step;
            if (($step['type'] ?? null) === FlowStepType::BRANCH->value) {
                array_push($all, ...self::flatten(self::arm($step, 'yes')), ...self::flatten(self::arm($step, 'no')));
            }
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<array<string, mixed>>
     */
    public static function arm(array $step, string $arm): array
    {
        $steps = $step[$arm] ?? [];

        return is_array($steps) ? array_values(array_filter($steps, 'is_array')) : [];
    }
}
