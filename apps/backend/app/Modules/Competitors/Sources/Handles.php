<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Sources;

/**
 * Normaliza lo que escribe el usuario para identificar una cuenta: «@usuario»,
 * «usuario» o la URL del perfil.
 */
final class Handles
{
    /**
     * @param  list<string>  $hosts  dominios de la red (instagram.com…)
     */
    public static function username(string $input, array $hosts, string $pattern = '/^[a-z0-9._]{1,30}$/'): ?string
    {
        $value = trim($input);
        $domains = implode('|', array_map(fn (string $h): string => preg_quote($h, '#'), $hosts));
        if (preg_match('#^(?:https?://)?(?:www\.|m\.)?(?:' . $domains . ')/@?([^/?\#\s]+)#i', $value, $m) === 1) {
            $value = $m[1];
        }
        // Los nombres de usuario no distinguen mayúsculas.
        $value = mb_strtolower(ltrim($value, '@'));

        return preg_match($pattern, $value) === 1 ? $value : null;
    }
}
