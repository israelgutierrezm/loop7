<?php

declare(strict_types=1);

namespace App\Modules\Reports\Rendering;

use Illuminate\Support\Carbon;

/**
 * Formato de cifras y fechas del informe (español: 12.345, +3,2 %, 1 de sep).
 */
final class ReportFormat
{
    public const DEFAULT_COLOR = '#4f46e5';

    public static function number(int|float|null $value, int $decimals = 0): string
    {
        return $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    }

    public static function signed(int|float|null $value, int $decimals = 0): string
    {
        if ($value === null) {
            return '—';
        }
        $sign = $value > 0 ? '+' : ($value < 0 ? '−' : '');

        return $sign . number_format(abs((float) $value), $decimals, ',', '.');
    }

    public static function percent(int|float|null $value, bool $signed = false, int $decimals = 1): string
    {
        if ($value === null) {
            return '—';
        }

        return ($signed ? self::signed($value, $decimals) : number_format((float) $value, $decimals, ',', '.')) . ' %';
    }

    /** Clase CSS de la tendencia: up, down o flat. */
    public static function trend(int|float|null $value): string
    {
        return match (true) {
            $value === null, (float) $value === 0.0 => 'flat',
            $value > 0 => 'up',
            default => 'down',
        };
    }

    public static function date(?string $date, string $format = 'j M Y', ?string $timezone = null): string
    {
        if ($date === null) {
            return '—';
        }
        $carbon = Carbon::parse($date);

        return ($timezone !== null ? $carbon->setTimezone($timezone) : $carbon)->locale('es')->translatedFormat($format);
    }

    /** «1–30 de septiembre de 2026», «15 de agosto – 14 de septiembre de 2026»… */
    public static function period(string $from, string $to): string
    {
        $a = Carbon::parse($from)->locale('es');
        $b = Carbon::parse($to)->locale('es');

        if ($a->isSameDay($b)) {
            return $a->translatedFormat('j \d\e F \d\e Y');
        }
        if ($a->isSameMonth($b)) {
            return $a->translatedFormat('j') . '–' . $b->translatedFormat('j \d\e F \d\e Y');
        }
        if ($a->isSameYear($b)) {
            return $a->translatedFormat('j \d\e F') . ' – ' . $b->translatedFormat('j \d\e F \d\e Y');
        }

        return $a->translatedFormat('j \d\e F \d\e Y') . ' – ' . $b->translatedFormat('j \d\e F \d\e Y');
    }

    public static function hour(int $hour): string
    {
        return sprintf('%02d:00', $hour);
    }

    /** Color de marca utilizable en CSS/SVG (#rrggbb) o el de la plataforma. */
    public static function color(?string $color): string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : self::DEFAULT_COLOR;
    }

    /** Enlaces sólo https en el PDF (nada de javascript: ni otros esquemas). */
    public static function safeUrl(?string $url): ?string
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }
}
