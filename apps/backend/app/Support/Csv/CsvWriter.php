<?php

declare(strict_types=1);

namespace App\Support\Csv;

/**
 * Escritura de CSV para abrir en hojas de cálculo:
 * - BOM UTF-8, para que Excel muestre bien acentos y eñes;
 * - neutraliza la inyección de fórmulas (OWASP "CSV Injection"): un texto que
 *   empieza por = + - @ tabulador o retorno se prefija con un apóstrofo, así un
 *   título como =HYPERLINK(...) se ve como texto y nunca se ejecuta.
 */
final class CsvWriter
{
    /**
     * @param  resource  $handle
     */
    public static function start($handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
    }

    /**
     * @param  resource  $handle
     * @param  list<string|int|float|null>  $cells
     */
    public static function row($handle, array $cells): void
    {
        fputcsv($handle, array_map(self::safe(...), $cells));
    }

    public static function safe(string|int|float|null $value): string|int|float
    {
        if (! is_string($value)) {
            return $value ?? '';
        }

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
