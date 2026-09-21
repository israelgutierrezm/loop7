<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

enum ConditionOperator: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';

    public function label(): string
    {
        return match ($this) {
            self::EQUALS => 'es igual a',
            self::NOT_EQUALS => 'es distinto de',
            self::CONTAINS => 'contiene',
            self::NOT_CONTAINS => 'no contiene',
        };
    }

    /**
     * Evalúa el operador contra un valor de contexto.
     */
    public function matches(string $actual, string $expected): bool
    {
        $a = mb_strtolower(trim($actual));
        $e = mb_strtolower(trim($expected));

        return match ($this) {
            self::EQUALS => $a === $e,
            self::NOT_EQUALS => $a !== $e,
            self::CONTAINS => $e === '' || str_contains($a, $e),
            self::NOT_CONTAINS => $e !== '' && ! str_contains($a, $e),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $o) => $o->value, self::cases());
    }
}
