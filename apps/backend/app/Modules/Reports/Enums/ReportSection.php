<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/**
 * Secciones que puede llevar un informe (en este orden en el PDF).
 */
enum ReportSection: string
{
    case SUMMARY = 'summary';
    case CHANNELS = 'channels';
    case GROWTH = 'growth';
    case TOP_POSTS = 'top_posts';
    case PUBLISHING = 'publishing';
    case BEST_TIMES = 'best_times';
    case COMPETITORS = 'competitors';

    public function label(): string
    {
        return match ($this) {
            self::SUMMARY => 'Resumen del periodo',
            self::CHANNELS => 'Resultados por red',
            self::GROWTH => 'Evolución de seguidores',
            self::TOP_POSTS => 'Publicaciones destacadas',
            self::PUBLISHING => 'Actividad de publicación',
            self::BEST_TIMES => 'Mejores horarios para publicar',
            self::COMPETITORS => 'Comparación con la competencia',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }

    /**
     * Las que lleva un informe nuevo si no se elige.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [self::SUMMARY->value, self::CHANNELS->value, self::GROWTH->value, self::TOP_POSTS->value, self::PUBLISHING->value];
    }
}
