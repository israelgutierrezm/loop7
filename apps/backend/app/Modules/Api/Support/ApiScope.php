<?php

declare(strict_types=1);

namespace App\Modules\Api\Support;

/**
 * Scopes de la API pública. Cada API key concede un subconjunto; los endpoints
 * exigen el scope correspondiente (docs/11).
 */
final class ApiScope
{
    public const BRANDS_READ = 'brands:read';
    public const CONTENT_READ = 'content:read';
    public const CONTENT_WRITE = 'content:write';
    public const ANALYTICS_READ = 'analytics:read';

    /**
     * @return array<string, string> scope => etiqueta
     */
    public static function catalog(): array
    {
        return [
            self::BRANDS_READ => 'Leer marcas',
            self::CONTENT_READ => 'Leer contenido',
            self::CONTENT_WRITE => 'Crear contenido',
            self::ANALYTICS_READ => 'Leer analítica',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::catalog());
    }
}
