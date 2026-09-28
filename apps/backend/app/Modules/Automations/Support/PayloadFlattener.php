<?php

declare(strict_types=1);

namespace App\Modules\Automations\Support;

use Illuminate\Support\Str;

/**
 * Convierte el JSON de un webhook entrante en un contexto plano de texto para
 * condiciones y variables: {"cliente": {"nombre": "Ana"}} → cliente.nombre.
 * Acotado en profundidad, número de campos y longitud de cada valor.
 */
final class PayloadFlattener
{
    private const MAX_DEPTH = 4;

    private const MAX_FIELDS = 100;

    private const MAX_VALUE_CHARS = 2000;

    /**
     * @param  array<mixed>  $payload
     * @return array<string, string>
     */
    public static function flatten(array $payload): array
    {
        $fields = [];
        self::walk($payload, '', 1, $fields);

        return $fields;
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<string, string>  $fields
     */
    private static function walk(array $data, string $prefix, int $depth, array &$fields): void
    {
        foreach ($data as $key => $value) {
            if (count($fields) >= self::MAX_FIELDS) {
                return;
            }

            $name = $prefix . preg_replace('/[^\w-]/u', '_', (string) $key);

            if (is_array($value)) {
                // Listas de valores simples: «a, b, c». Objetos: un nivel más.
                if (array_is_list($value) && array_filter($value, fn ($v) => is_array($v)) === []) {
                    $fields[$name] = Str::limit(implode(', ', array_map(self::scalar(...), $value)), self::MAX_VALUE_CHARS);
                } elseif ($depth < self::MAX_DEPTH) {
                    self::walk($value, $name . '.', $depth + 1, $fields);
                }

                continue;
            }

            $fields[$name] = Str::limit(self::scalar($value), self::MAX_VALUE_CHARS);
        }
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
