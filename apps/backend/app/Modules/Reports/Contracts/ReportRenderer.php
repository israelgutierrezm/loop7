<?php

declare(strict_types=1);

namespace App\Modules\Reports\Contracts;

/**
 * Convierte la foto de un informe (ReportDataBuilder) en un PDF. El dominio no
 * depende de la librería concreta (hoy dompdf).
 */
interface ReportRenderer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $logo  logo de marca blanca como data URI (PNG)
     * @return string contenido binario del PDF
     */
    public function render(array $data, ?string $logo): string;
}
