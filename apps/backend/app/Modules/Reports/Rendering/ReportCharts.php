<?php

declare(strict_types=1);

namespace App\Modules\Reports\Rendering;

/**
 * Gráficos del PDF como SVG en data URI (dompdf no ejecuta JavaScript ni carga
 * recursos remotos). Sin texto dentro del SVG: las etiquetas van en el HTML.
 */
final class ReportCharts
{
    /**
     * Línea con área de una serie (los huecos null no se dibujan).
     *
     * @param  list<int|float|null>  $values
     * @return array{uri: string, min: float, max: float}|null null si hay menos de 2 puntos
     */
    public static function line(array $values, string $color, int $width = 680, int $height = 170): ?array
    {
        $known = array_values(array_filter($values, fn ($v): bool => $v !== null));
        if (count($known) < 2) {
            return null;
        }

        $color = ReportFormat::color($color);
        $min = (float) min($known);
        $max = (float) max($known);
        if ($max - $min < 1e-9) {
            $min -= 1;
            $max += 1;
        }
        $padding = ($max - $min) * 0.1;
        $low = $min - $padding;
        $high = $max + $padding;
        $count = count($values);
        $step = $count > 1 ? $width / ($count - 1) : $width;

        $points = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            $x = round($i * $step, 2);
            $y = round($height - (((float) $value - $low) / ($high - $low)) * $height, 2);
            $points[] = "{$x},{$y}";
        }
        [$firstX] = explode(',', $points[0]);
        [$lastX] = explode(',', $points[count($points) - 1]);
        $line = implode(' ', $points);
        $area = "{$firstX},{$height} {$line} {$lastX},{$height}";

        $grid = '';
        for ($i = 1; $i < 4; $i++) {
            $y = round($height * $i / 4, 2);
            $grid .= "<line x1=\"0\" y1=\"{$y}\" x2=\"{$width}\" y2=\"{$y}\" stroke=\"#e2e8f0\" stroke-width=\"1\"/>";
        }

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\" viewBox=\"0 0 {$width} {$height}\">"
            . $grid
            . "<polygon points=\"{$area}\" fill=\"{$color}\" fill-opacity=\"0.12\" stroke=\"none\"/>"
            . "<polyline points=\"{$line}\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"2.5\" stroke-linejoin=\"round\"/>"
            . '</svg>';

        return ['uri' => 'data:image/svg+xml;base64,' . base64_encode($svg), 'min' => $min, 'max' => $max];
    }
}
