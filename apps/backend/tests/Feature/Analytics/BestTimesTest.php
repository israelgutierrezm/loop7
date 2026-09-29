<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mejores horarios para publicar (docs/05): agrupación por la hora local de la
 * marca, ventana de publicaciones medidas, fechas sugeridas, filtro por red,
 * permisos, aislamiento y plan.
 */
class BestTimesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        // Lunes 28 de septiembre de 2026, 12:00 en Ciudad de México (UTC−6, sin horario de verano).
        Carbon::setTestNow('2026-09-28 18:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: Organization, 1: User, 2: Brand, 3: SocialConnectionDestination}
     */
    private function brand(string $timezone = 'America/Mexico_City', string $plan = 'professional'): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, $plan);
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'timezone' => $timezone]);

        return [$org, $owner, $brand, $this->destination($brand, 'fake')];
    }

    private function destination(Brand $brand, string $provider): SocialConnectionDestination
    {
        $connection = SocialConnection::query()->create([
            'organization_id' => $brand->organization_id,
            'brand_id' => $brand->id,
            'provider' => $provider,
            'status' => 'connected',
            'external_account_name' => "Cuenta {$provider}",
        ]);

        return SocialConnectionDestination::query()->create([
            'organization_id' => $brand->organization_id,
            'social_connection_id' => $connection->id,
            'external_id' => "dest-{$provider}-" . Str::random(6),
            'name' => "Página {$provider}",
            'type' => 'page',
        ]);
    }

    /**
     * Publicación ya medida. $earlier crea antes un snapshot con otro valor: se
     * debe usar el último (los snapshots guardan totales acumulados).
     *
     * @param list<string> $publishedAtUtc
     */
    private function publish(SocialConnectionDestination $destination, array $publishedAtUtc, int $engagement, ?int $earlier = null): void
    {
        $connection = SocialConnection::query()->withoutGlobalScopes()->findOrFail($destination->social_connection_id);

        foreach ($publishedAtUtc as $when) {
            $content = ContentItem::query()->create([
                'organization_id' => $connection->organization_id,
                'brand_id' => $connection->brand_id,
                'title' => 'Publicación medida',
                'status' => 'published',
            ]);
            $variant = $content->variants()->create([
                'organization_id' => $connection->organization_id,
                'provider' => $connection->provider,
                'body' => 'Hola',
                'format' => 'text',
            ]);
            $publishedAt = Carbon::parse($when, 'UTC');
            $target = PublicationTarget::query()->create([
                'organization_id' => $connection->organization_id,
                'post_variant_id' => $variant->id,
                'social_connection_destination_id' => $destination->id,
                'status' => TargetStatus::PUBLISHED->value,
                'remote_id' => 'post-' . Str::random(10),
                'published_at' => $publishedAt,
            ]);

            foreach (array_filter([1 => $earlier, 3 => $engagement], fn ($v) => $v !== null) as $days => $value) {
                PostMetricSnapshot::query()->create([
                    'organization_id' => $connection->organization_id,
                    'brand_id' => $connection->brand_id,
                    'publication_target_id' => $target->id,
                    'provider' => $connection->provider,
                    'remote_id' => $target->remote_id,
                    'date' => $publishedAt->copy()->addDays($days)->toDateString(),
                    'impressions' => $value * 10,
                    'reach' => $value * 8,
                    'engagement' => $value,
                ]);
            }
        }
    }

    /**
     * Doce publicaciones «normales» (100 interacciones) los domingos por la mañana.
     */
    private function typicalWeek(SocialConnectionDestination $destination): void
    {
        $posts = [];
        foreach (['2026-09-20', '2026-09-13', '2026-09-06'] as $sunday) {
            foreach (['14:00', '16:00', '18:00', '20:00'] as $time) {
                $posts[] = "{$sunday} {$time}";
            }
        }
        $this->publish($destination, $posts, 100);
    }

    private function url(Brand $brand, string $query = ''): string
    {
        return "/api/v1/brands/{$brand->public_id}/analytics/best-times" . ($query !== '' ? "?{$query}" : '');
    }

    public function test_recomienda_por_la_hora_local_de_la_marca_con_sus_proximas_fechas(): void
    {
        [$org, $owner, $brand, $destination] = $this->brand();
        $this->typicalWeek($destination);
        // Martes a las 10:00 locales (16:00 UTC): rinden 2,5 veces lo habitual.
        $this->publish($destination, ['2026-09-22 16:00', '2026-09-15 16:00', '2026-09-08 16:00', '2026-09-01 16:00'], 250, earlier: 1);

        $response = $this->actingInOrganization($owner, $org)->getJson($this->url($brand))->assertOk();

        $response->assertJsonPath('data.timezone', 'America/Mexico_City')
            ->assertJsonPath('data.sample', 16)
            ->assertJsonPath('data.sufficient', true)
            ->assertJsonPath('data.top.0.weekday', 2)
            ->assertJsonPath('data.top.0.hour', 10)
            ->assertJsonPath('data.top.0.posts', 4)
            ->assertJsonPath('data.top.0.lift', 100)
            ->assertJsonPath('data.counts.1.10', 4)
            ->assertJsonCount(1, 'data.top');
        $this->assertCount(7, $response->json('data.heatmap'));
        $this->assertCount(24, $response->json('data.heatmap.0'));

        // Próxima semana: el martes 29 a las 10:00 locales.
        $this->assertSame([[
            'at' => '2026-09-29T16:00:00+00:00', 'weekday' => 2, 'hour' => 10, 'lift' => 100,
        ]], $response->json('data.occurrences'));

        // Con un rango de dos semanas, dos fechas en orden.
        $twoWeeks = $this->actingInOrganization($owner, $org)
            ->getJson($this->url($brand, 'from=2026-09-28T18:00:00Z&to=2026-10-12T18:00:00Z'))
            ->assertOk();
        $this->assertSame(
            ['2026-09-29T16:00:00+00:00', '2026-10-06T16:00:00+00:00'],
            array_column($twoWeeks->json('data.occurrences'), 'at'),
        );
    }

    public function test_respeta_el_cambio_de_horario_de_la_zona_de_la_marca(): void
    {
        // Viernes 20 de noviembre: la ventana abarca horario de verano y de invierno de Madrid.
        Carbon::setTestNow('2026-11-20 12:00:00');
        [$org, $owner, $brand, $destination] = $this->brand('Europe/Madrid');
        $this->publish($destination, [
            '2026-09-06 10:00', '2026-09-06 16:00', '2026-09-13 10:00', '2026-09-13 16:00',
            '2026-09-20 10:00', '2026-09-20 16:00', '2026-09-27 10:00', '2026-09-27 16:00',
        ], 100);
        // Jueves a las 10:00 de Madrid: 08:00 UTC en verano, 09:00 UTC en invierno.
        $this->publish($destination, [
            '2026-09-03 08:00', '2026-09-10 08:00', '2026-10-01 08:00',
            '2026-10-29 09:00', '2026-11-05 09:00', '2026-11-12 09:00',
        ], 250);

        $response = $this->actingInOrganization($owner, $org)->getJson($this->url($brand))->assertOk();

        $response->assertJsonPath('data.counts.3.10', 6)
            ->assertJsonPath('data.counts.3.9', 0)
            ->assertJsonPath('data.top.0.weekday', 4)
            ->assertJsonPath('data.top.0.hour', 10)
            // El jueves 26 a las 10:00 de Madrid ya es horario de invierno (UTC+1).
            ->assertJsonPath('data.occurrences.0.at', '2026-11-26T09:00:00+00:00');
    }

    public function test_solo_cuenta_publicaciones_medidas_de_la_marca_en_los_ultimos_90_dias(): void
    {
        [$org, $owner, $brand, $destination] = $this->brand();
        $this->typicalWeek($destination);
        // Hace 24 h (métricas aún sin asentar) y hace 105 días: fuera.
        $this->publish($destination, ['2026-09-27 18:00', '2026-06-15 16:00'], 900);
        // Otra marca de la misma organización: no se mezcla.
        $other = Brand::factory()->create(['organization_id' => $org->id]);
        $this->publish($this->destination($other, 'fake'), ['2026-09-22 16:00', '2026-09-15 16:00'], 900);

        $this->actingInOrganization($owner, $org)->getJson($this->url($brand))
            ->assertOk()
            ->assertJsonPath('data.sample', 12)
            ->assertJsonPath('data.top', []);
    }

    public function test_filtra_por_red(): void
    {
        [$org, $owner, $brand, $fake] = $this->brand();
        $facebook = $this->destination($brand, 'facebook');
        $this->typicalWeek($fake);
        $this->typicalWeek($facebook);
        // Prueba: martes a las 10:00 locales. Facebook: viernes a las 19:00 locales (01:00 UTC del sábado).
        $this->publish($fake, ['2026-09-22 16:00', '2026-09-15 16:00', '2026-09-08 16:00'], 250);
        $this->publish($facebook, ['2026-09-26 01:00', '2026-09-19 01:00', '2026-09-12 01:00'], 250);

        $all = $this->actingInOrganization($owner, $org)->getJson($this->url($brand))->assertOk();
        $this->assertEqualsCanonicalizing(
            [[2, 10], [5, 19]],
            array_map(fn (array $t) => [$t['weekday'], $t['hour']], $all->json('data.top')),
        );

        $this->actingInOrganization($owner, $org)->getJson($this->url($brand, 'providers[]=facebook'))
            ->assertOk()
            ->assertJsonPath('data.providers', ['facebook'])
            ->assertJsonPath('data.sample', 15)
            ->assertJsonPath('data.top.0.weekday', 5)
            ->assertJsonPath('data.top.0.hour', 19)
            ->assertJsonCount(1, 'data.top');

        $this->actingInOrganization($owner, $org)->getJson($this->url($brand, 'providers[]=myspace'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('providers.0');
    }

    public function test_sin_datos_suficientes_no_recomienda(): void
    {
        [$org, $owner, $brand, $destination] = $this->brand();
        $this->publish($destination, ['2026-09-22 16:00', '2026-09-15 16:00', '2026-09-08 16:00'], 250);

        $this->actingInOrganization($owner, $org)->getJson($this->url($brand))
            ->assertOk()
            ->assertJsonPath('data.sample', 3)
            ->assertJsonPath('data.min_posts', 10)
            ->assertJsonPath('data.sufficient', false)
            ->assertJsonPath('data.top', [])
            ->assertJsonPath('data.occurrences', []);
    }

    public function test_requiere_permiso_de_analitica_y_acceso_a_la_marca(): void
    {
        [$org, , $brand] = $this->brand();

        $billing = $this->addMember($org, OrganizationRole::BILLING->value);
        $this->actingInOrganization($billing, $org)->getJson($this->url($brand))->assertForbidden();

        $restricted = $this->addMember($org, OrganizationRole::PUBLISHER->value, allBrandsAccess: false);
        $this->actingInOrganization($restricted, $org)->getJson($this->url($brand))->assertForbidden();

        $publisher = $this->addMember($org, OrganizationRole::PUBLISHER->value);
        $this->actingInOrganization($publisher, $org)->getJson($this->url($brand))->assertOk();
    }

    public function test_no_expone_marcas_de_otra_organizacion(): void
    {
        [$orgA, $ownerA] = $this->brand();
        [, , $brandB] = $this->brand();

        $this->actingInOrganization($ownerA, $orgA)->getJson($this->url($brandB))->assertNotFound();
    }

    public function test_el_historial_de_muestra_alimenta_los_mejores_horarios(): void
    {
        Http::fake();
        [$org, $owner, $brand, $fake] = $this->brand();
        $real = $this->destination($brand, 'facebook');

        $this->artisan('analytics:demo', ['brand' => $brand->public_id, '--history' => true])->assertSuccessful();
        // Repetirlo no duplica el historial.
        $this->artisan('analytics:demo', ['brand' => $brand->public_id, '--history' => true])->assertSuccessful();

        // Dos al día durante 58 días, sólo en la cuenta simulada y sin remote_id (la sincronización no las pisa).
        $demo = PostMetricSnapshot::query()->withoutGlobalScopes()->where('remote_id', 'like', 'demo-%');
        $this->assertSame(116, (clone $demo)->count());
        $this->assertSame(116, (clone $demo)->where('remote_id', 'like', "demo-{$fake->id}-%")->count());
        $this->assertSame(0, PublicationTarget::query()->withoutGlobalScopes()
            ->where('social_connection_destination_id', $real->id)->count());
        $this->assertSame(0, PublicationTarget::query()->withoutGlobalScopes()
            ->where('social_connection_destination_id', $fake->id)->whereNotNull('remote_id')->count());
        // Fechadas cuando se habrían creado: el cupo del mes sólo cuenta las de este mes.
        $this->assertLessThan(116, app(UsageService::class)->scheduledPostsThisMonth($org));
        $this->assertTrue(Carbon::parse(PublicationTarget::query()->withoutGlobalScopes()
            ->where('social_connection_destination_id', $fake->id)->max('created_at'))->lt(now()->subDays(3)));

        $top = $this->actingInOrganization($owner, $org)->getJson($this->url($brand))
            ->assertOk()
            ->assertJsonPath('data.sufficient', true)
            ->json('data.top');
        $this->assertSame([[2, 10], [4, 10], [3, 19]], array_map(fn (array $t) => [$t['weekday'], $t['hour']], $top));
    }

    public function test_el_historial_de_muestra_no_se_genera_en_produccion(): void
    {
        [, , $brand] = $this->brand();
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('analytics:demo', ['brand' => $brand->public_id, '--history' => true])->assertFailed();

        $this->assertSame(0, PostMetricSnapshot::query()->withoutGlobalScopes()->count());
    }

    public function test_requiere_plan_con_analitica_avanzada(): void
    {
        [$org, $owner, $brand] = $this->brand(plan: 'starter');

        $this->actingInOrganization($owner, $org)->getJson($this->url($brand))
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.analytics_advanced');
    }
}
