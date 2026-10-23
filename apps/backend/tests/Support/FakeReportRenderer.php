<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Reports\Contracts\ReportRenderer;

/**
 * Render de informes de prueba: guarda lo que se le pide pintar y devuelve un
 * PDF mínimo (las pruebas del PDF real usan DompdfReportRenderer).
 */
final class FakeReportRenderer implements ReportRenderer
{
    /** @var list<array{data: array<string, mixed>, logo: string|null}> */
    public array $calls = [];

    public function render(array $data, ?string $logo): string
    {
        $this->calls[] = ['data' => $data, 'logo' => $logo];

        return "%PDF-1.4\n% informe de prueba\n%%EOF\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function lastData(): array
    {
        return $this->calls[count($this->calls) - 1]['data'];
    }
}
