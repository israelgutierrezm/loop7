<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Modules\Reports\Enums\ReportFrequency;
use App\Modules\Reports\Enums\ReportPeriod;
use App\Modules\Reports\Rendering\ReportCharts;
use App\Modules\Reports\Rendering\ReportFormat;
use App\Modules\Reports\Services\ReportSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Periodos y próximos envíos de los informes (días completos y horas de la
 * zona de la marca, con cambio de horario), y formato del PDF.
 */
class ReportScheduleTest extends TestCase
{
    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    public function test_periodos_con_dias_completos_de_la_marca(): void
    {
        // Jueves 15 de octubre, 01:00 UTC = miércoles 14, 19:00 en Ciudad de México.
        $now = $this->at('2026-10-15 01:00:00');
        $tz = 'America/Mexico_City';

        $range = fn (ReportPeriod $p): array => array_map(fn (CarbonImmutable $d): string => $d->toDateString(), $p->range($tz, $now));

        $this->assertSame(['2026-10-07', '2026-10-13'], $range(ReportPeriod::LAST_7_DAYS));
        $this->assertSame(['2026-09-14', '2026-10-13'], $range(ReportPeriod::LAST_30_DAYS));
        $this->assertSame(['2026-10-05', '2026-10-11'], $range(ReportPeriod::PREVIOUS_WEEK));
        $this->assertSame(['2026-09-01', '2026-09-30'], $range(ReportPeriod::PREVIOUS_MONTH));
        // 1 de marzo: el mes anterior es febrero entero.
        $this->assertSame(['2026-02-01', '2026-02-28'], array_map(fn ($d) => $d->toDateString(), ReportPeriod::PREVIOUS_MONTH->range('UTC', $this->at('2026-03-01 10:00:00'))));
    }

    public function test_proximo_envio_semanal_y_mensual(): void
    {
        $tz = 'America/Mexico_City';
        $now = $this->at('2026-10-15 15:00:00'); // jueves 09:00 en la marca

        $this->assertNull(ReportSchedule::next(null, null, 8, $tz, $now));
        $this->assertSame('2026-10-19 14:00:00', ReportSchedule::next(ReportFrequency::WEEKLY, 1, 8, $tz, $now)?->format('Y-m-d H:i:s'));
        // Hoy jueves a las 10:00 aún no pasó: hoy mismo.
        $this->assertSame('2026-10-15 16:00:00', ReportSchedule::next(ReportFrequency::WEEKLY, 4, 10, $tz, $now)?->format('Y-m-d H:i:s'));
        // Hoy jueves a las 09:00 ya es ahora: la semana que viene.
        $this->assertSame('2026-10-22 15:00:00', ReportSchedule::next(ReportFrequency::WEEKLY, 4, 9, $tz, $now)?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 14:00:00', ReportSchedule::next(ReportFrequency::MONTHLY, 1, 8, $tz, $now)?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 14:00:00', ReportSchedule::next(ReportFrequency::MONTHLY, 28, 8, $tz, $now)?->format('Y-m-d H:i:s'));
    }

    public function test_respeta_el_cambio_de_horario(): void
    {
        // Madrid pasa a horario de verano el domingo 29 de marzo de 2026.
        $before = $this->at('2026-03-27 12:00:00');
        $next = ReportSchedule::next(ReportFrequency::WEEKLY, 1, 9, 'Europe/Madrid', $before);

        // Lunes 30 a las 09:00 de Madrid ya es UTC+2.
        $this->assertSame('2026-03-30 07:00:00', $next?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-23 08:00:00', ReportSchedule::next(ReportFrequency::WEEKLY, 1, 9, 'Europe/Madrid', $this->at('2026-03-20 12:00:00'))?->format('Y-m-d H:i:s'));
    }

    public function test_formato_y_grafico(): void
    {
        $this->assertSame('12.345', ReportFormat::number(12345));
        $this->assertSame('—', ReportFormat::number(null));
        $this->assertSame('+3,2 %', ReportFormat::percent(3.2, true));
        $this->assertSame('−1.500', ReportFormat::signed(-1500));
        $this->assertSame('#4f46e5', ReportFormat::color('javascript:'));
        $this->assertNull(ReportFormat::safeUrl('http://inseguro.test'));

        $this->assertNull(ReportCharts::line([null, 5], '#0f766e'));
        $chart = ReportCharts::line([10, null, 20, 15], '#0f766e');
        $this->assertNotNull($chart);
        $svg = (string) base64_decode(substr($chart['uri'], strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('#0f766e', $svg);
        $this->assertSame([10.0, 20.0], [$chart['min'], $chart['max']]);
    }
}
