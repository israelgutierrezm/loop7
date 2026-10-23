<?php

declare(strict_types=1);

namespace App\Modules\Reports\Rendering;

use App\Modules\Reports\Contracts\ReportRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF con dompdf (LGPL-2.1) a partir de la plantilla Blade del informe. Sin
 * recursos remotos ni PHP/JavaScript en el documento: imágenes y gráficos van
 * incrustados (data URI), así que el render nunca hace peticiones de red (SSRF).
 */
final class DompdfReportRenderer implements ReportRenderer
{
    public function render(array $data, ?string $logo): string
    {
        $color = ReportFormat::color($data['branding']['color'] ?? null);
        $growth = $data['growth'] ?? null;

        $html = view('reports::pdf', [
            'report' => $data,
            'logo' => $logo,
            'color' => $color,
            'f' => new ReportFormat(),
            'growthChart' => is_array($growth) ? ReportCharts::line($growth['followers'] ?? [], $color) : null,
        ])->render();

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
