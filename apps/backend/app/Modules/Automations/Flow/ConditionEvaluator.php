<?php

declare(strict_types=1);

namespace App\Modules\Automations\Flow;

use App\Modules\Automations\Enums\ConditionMatch;
use App\Modules\Automations\Enums\ConditionOperator;

/**
 * Evalúa las condiciones de un paso «Condición» contra el contexto plano del
 * disparador (todas o alguna).
 */
final class ConditionEvaluator
{
    /**
     * @param  array<string, mixed>  $step  {match, conditions}
     * @param  array<string, mixed>  $context
     */
    public static function matches(array $step, array $context): bool
    {
        $conditions = is_array($step['conditions'] ?? null) ? $step['conditions'] : [];
        $any = ConditionMatch::tryFrom((string) ($step['match'] ?? '')) === ConditionMatch::ANY;

        $results = [];
        foreach ($conditions as $condition) {
            $operator = is_array($condition) ? ConditionOperator::tryFrom((string) ($condition['operator'] ?? '')) : null;
            if ($operator === null) {
                continue;
            }
            $actual = $context[(string) ($condition['field'] ?? '')] ?? '';
            $results[] = $operator->matches(is_scalar($actual) ? (string) $actual : '', (string) ($condition['value'] ?? ''));
        }

        if ($results === []) {
            return ! $any;
        }

        return $any ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }
}
