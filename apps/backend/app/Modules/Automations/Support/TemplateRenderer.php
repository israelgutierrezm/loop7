<?php

declare(strict_types=1);

namespace App\Modules\Automations\Support;

/**
 * Sustituye tokens {campo} por valores del contexto del disparador (admite los
 * campos anidados de un webhook entrante: {cliente.nombre}). Un token sin valor
 * se deja tal cual para que se note en el resultado.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function render(string $template, array $context): string
    {
        return preg_replace_callback('/\{([\w.-]+)\}/u', function (array $m) use ($context): string {
            $value = $context[$m[1]] ?? null;

            return is_scalar($value) ? (string) $value : $m[0];
        }, $template) ?? $template;
    }
}
