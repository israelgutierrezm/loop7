<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

enum ConditionOperator: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case STARTS_WITH = 'starts_with';
    case IS_EMPTY = 'is_empty';
    case IS_NOT_EMPTY = 'is_not_empty';

    public function label(): string
    {
        return match ($this) {
            self::EQUALS => 'es igual a',
            self::NOT_EQUALS => 'es distinto de',
            self::CONTAINS => 'contiene',
            self::NOT_CONTAINS => 'no contiene',
            self::STARTS_WITH => 'empieza por',
            self::IS_EMPTY => 'está vacío',
            self::IS_NOT_EMPTY => 'no está vacío',
        };
    }

    /**
     * ¿Compara con un valor? «Está vacío» y «no está vacío» no lo necesitan.
     */
    public function needsValue(): bool
    {
        return ! in_array($this, [self::IS_EMPTY, self::IS_NOT_EMPTY], true);
    }

    /**
     * Evalúa el operador contra un valor de contexto (sin distinguir mayúsculas).
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
            self::STARTS_WITH => str_starts_with($a, $e),
            self::IS_EMPTY => $a === '',
            self::IS_NOT_EMPTY => $a !== '',
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
