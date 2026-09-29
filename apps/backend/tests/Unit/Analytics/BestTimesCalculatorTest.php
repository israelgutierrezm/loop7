<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\BestTimes\BestTimesCalculator;
use App\Modules\Analytics\BestTimes\PostSample;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Cálculo de los mejores horarios (docs/05): comparación con lo habitual de cada
 * cuenta, contracción hacia la media, suavizado y franjas separadas.
 */
class BestTimesCalculatorTest extends TestCase
{
    private const MONDAY = 1;
    private const TUESDAY = 2;
    private const THURSDAY = 4;
    private const FRIDAY = 5;
    private const SUNDAY = 7;

    /**
     * Publicaciones «normales» de una cuenta, en franjas alejadas de las que se prueban.
     *
     * @return list<PostSample>
     */
    private function typical(string $account, int $count, int $engagement = 100): array
    {
        $samples = [];
        for ($i = 0; $i < $count; $i++) {
            $samples[] = new PostSample($account, self::SUNDAY, ($i * 2) % 24, $engagement);
        }

        return $samples;
    }

    /**
     * @return list<PostSample>
     */
    private function repeat(string $account, int $weekday, int $hour, int $engagement, int $times): array
    {
        return array_fill(0, $times, new PostSample($account, $weekday, $hour, $engagement));
    }

    public function test_sin_datos_suficientes_no_recomienda(): void
    {
        $samples = [...$this->typical('a', 6), ...$this->repeat('a', self::TUESDAY, 10, 300, 3)];

        $result = (new BestTimesCalculator())->calculate($samples);

        $this->assertSame(9, $result->sample);
        $this->assertFalse($result->sufficient);
        $this->assertSame([], $result->top);
        // El mapa de calor sí se calcula (la vista lo muestra como avance).
        $this->assertNotNull($result->scores[BestTimesCalculator::slot(self::TUESDAY, 10)]);
    }

    public function test_recomienda_la_franja_que_rinde_mejor_que_lo_habitual(): void
    {
        $samples = [...$this->typical('a', 12), ...$this->repeat('a', self::TUESDAY, 10, 250, 4)];

        $result = (new BestTimesCalculator())->calculate($samples);

        $this->assertTrue($result->sufficient);
        $this->assertSame(['weekday' => self::TUESDAY, 'hour' => 10], array_intersect_key($result->top[0], ['weekday' => 0, 'hour' => 0]));
        $this->assertSame(4, $result->top[0]['posts']);
        $this->assertGreaterThan(50, $result->top[0]['lift']);
        // Lo habitual (1,0) no se recomienda.
        $this->assertCount(1, $result->top);
    }

    public function test_compara_cada_publicacion_con_su_propia_cuenta(): void
    {
        $samples = [
            // Cuenta grande: el lunes a las 9 rinde lo de siempre (1000).
            ...$this->typical('grande', 8, 1000),
            ...$this->repeat('grande', self::MONDAY, 9, 1000, 3),
            // Cuenta pequeña: el jueves a las 18 triplica lo suyo (10 → 30).
            ...$this->typical('pequena', 8, 10),
            ...$this->repeat('pequena', self::THURSDAY, 18, 30, 3),
        ];

        $top = (new BestTimesCalculator())->calculate($samples)->top;

        $this->assertSame([self::THURSDAY, 18], [$top[0]['weekday'], $top[0]['hour']]);
        $this->assertNotContains([self::MONDAY, 9], array_map(fn (array $t) => [$t['weekday'], $t['hour']], $top));
    }

    public function test_una_publicacion_viral_no_decide_sola(): void
    {
        $samples = [
            ...$this->typical('a', 8),
            new PostSample('a', self::MONDAY, 8, 2000),            // 20 veces lo habitual, una vez
            ...$this->repeat('a', self::THURSDAY, 17, 250, 3),    // 2,5 veces, tres veces
        ];

        $top = (new BestTimesCalculator())->calculate($samples)->top;

        $this->assertSame([self::THURSDAY, 17], [$top[0]['weekday'], $top[0]['hour']]);
        $this->assertSame([self::MONDAY, 8], [$top[1]['weekday'], $top[1]['hour']]);
        $this->assertGreaterThan($top[1]['lift'], $top[0]['lift']);
    }

    public function test_las_franjas_recomendadas_no_son_horas_seguidas(): void
    {
        $samples = [
            ...$this->typical('a', 11),
            ...$this->repeat('a', self::TUESDAY, 10, 250, 3),
            ...$this->repeat('a', self::TUESDAY, 11, 240, 3),
            ...$this->repeat('a', self::FRIDAY, 19, 200, 3),
        ];

        $top = (new BestTimesCalculator())->calculate($samples)->top;
        $picked = array_map(fn (array $t) => [$t['weekday'], $t['hour']], $top);

        $this->assertContains([self::TUESDAY, 10], $picked);
        $this->assertContains([self::FRIDAY, 19], $picked);
        $this->assertNotContains([self::TUESDAY, 11], $picked);
    }

    public function test_una_cuenta_sin_interacciones_no_aporta_senal(): void
    {
        $samples = [...$this->typical('a', 10), ...$this->typical('muda', 5, 0)];

        $result = (new BestTimesCalculator())->calculate($samples);

        $this->assertSame(10, $result->sample);
    }

    public function test_mapa_de_calor_y_conteos_por_franja(): void
    {
        $samples = [...$this->typical('a', 10), ...$this->repeat('a', self::FRIDAY, 13, 200, 2)];

        $result = (new BestTimesCalculator())->calculate($samples);

        $this->assertSame(2, $result->counts[BestTimesCalculator::slot(self::FRIDAY, 13)]);
        // Las horas vecinas reciben parte de la señal (suavizado) aunque no tengan publicaciones.
        $this->assertSame(0, $result->counts[BestTimesCalculator::slot(self::FRIDAY, 14)]);
        $this->assertGreaterThan(1.0, $result->scores[BestTimesCalculator::slot(self::FRIDAY, 14)]);
        // Sin publicaciones cerca: sin dato.
        $this->assertNull($result->scores[BestTimesCalculator::slot(self::MONDAY, 3)]);
        $this->assertCount(168, $result->scores);
    }

    public function test_rechaza_muestras_fuera_de_rango(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PostSample('a', 8, 10, 5);
    }
}
