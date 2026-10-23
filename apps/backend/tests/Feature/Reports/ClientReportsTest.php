<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Analytics\Models\AccountMetricSnapshot;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Models\OrganizationEntitlementOverride;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Reports\Contracts\ReportRenderer;
use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Enums\RunTrigger;
use App\Modules\Reports\Models\ClientReport;
use App\Modules\Reports\Models\ClientReportRun;
use App\Modules\Reports\Notifications\ClientReportMail;
use App\Modules\Reports\Rendering\DompdfReportRenderer;
use App\Modules\Reports\Rendering\ReportFormat;
use App\Modules\Reports\Services\ReportGenerator;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeReportRenderer;
use Tests\TestCase;

/**
 * Informes para clientes (docs/05): alta con límites del plan y permisos,
 * datos del periodo en la zona de la marca, PDF privado con descarga firmada,
 * envío por correo sin repetir, envío programado y retención.
 */
class ClientReportsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Mexico_City';

    private FakeReportRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Storage::fake('local');
        $this->renderer = new FakeReportRenderer();
        $this->app->instance(ReportRenderer::class, $this->renderer);
        // Jueves 15 de octubre de 2026, 09:00 en Ciudad de México.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));
    }

    /**
     * @return array{0: User, 1: Organization, 2: Brand}
     */
    private function scenario(string $plan = 'professional', string $email = 'duena@agencia.com'): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization(['email' => $email]);
        $this->setOrganizationPlan($org, $plan);
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Aurora', 'timezone' => self::TZ]);

        return [$owner, $org, $brand];
    }

    private function url(Brand $brand, string $path = ''): string
    {
        return "/api/v1/brands/{$brand->public_id}/reports{$path}";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Informe mensual',
            'period' => 'previous_month',
            'sections' => ['summary', 'channels', 'growth', 'top_posts', 'publishing'],
            'intro' => 'Hola, aquí va el resumen del mes.',
            'recipients' => ['Ana@Cliente.com', 'luis@cliente.com'],
            'frequency' => null,
            'send_day' => null,
            'send_hour' => 8,
        ];
    }

    private function destination(Organization $org, Brand $brand, string $provider, string $name): SocialConnectionDestination
    {
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => $provider,
            'status' => 'connected', 'external_account_name' => $name,
        ]);

        return SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => $provider . '-' . $name, 'name' => $name, 'type' => 'account',
        ]);
    }

    private function snapshot(Organization $org, Brand $brand, SocialConnectionDestination $destination, string $provider, string $date, int $followers, int $reach = 0, int $impressions = 0, int $engagement = 0): void
    {
        AccountMetricSnapshot::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'social_connection_destination_id' => $destination->id,
            'provider' => $provider, 'date' => $date, 'followers' => $followers, 'reach' => $reach,
            'impressions' => $impressions, 'engagement' => $engagement, 'posts_count' => 0,
        ]);
    }

    /**
     * Publicación de la marca; `$publishedAt` en la hora de la marca.
     *
     * @param  array<string, int>  $metrics  fecha => interacciones (la última manda)
     */
    private function publishedPost(Organization $org, Brand $brand, SocialConnectionDestination $destination, string $provider, string $title, ?string $publishedAt, array $metrics = [], TargetStatus $status = TargetStatus::PUBLISHED, ?string $scheduledAt = null): PublicationTarget
    {
        $content = ContentItem::query()->create(['organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => $title, 'status' => 'published']);
        $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => $provider, 'body' => $title, 'format' => 'text']);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id,
            'status' => $status->value,
            'remote_id' => 'remote-' . $title,
            'remote_url' => 'https://example.com/p/' . rawurlencode($title),
            'published_at' => $publishedAt !== null ? CarbonImmutable::parse($publishedAt, self::TZ)->utc() : null,
            'scheduled_at' => $scheduledAt !== null ? CarbonImmutable::parse($scheduledAt, self::TZ)->utc() : null,
        ]);
        foreach ($metrics as $date => $engagement) {
            PostMetricSnapshot::query()->create([
                'organization_id' => $org->id, 'brand_id' => $brand->id, 'publication_target_id' => $target->id,
                'provider' => $provider, 'remote_id' => $target->remote_id, 'date' => $date,
                'impressions' => $engagement * 10, 'reach' => $engagement * 5, 'likes' => $engagement - 2,
                'comments' => 2, 'shares' => 0, 'clicks' => 0, 'engagement' => $engagement,
            ]);
        }

        return $target;
    }

    /**
     * Septiembre de 2026 con dos cuentas, publicaciones y el agosto anterior para comparar.
     */
    private function seedSeptember(Organization $org, Brand $brand): void
    {
        $page = $this->destination($org, $brand, 'fake', 'Página Aurora');
        $insta = $this->destination($org, $brand, 'instagram', '@cafeaurora');

        $this->snapshot($org, $brand, $page, 'fake', '2026-08-20', 900, 100, 300, 70);
        $this->snapshot($org, $brand, $page, 'fake', '2026-09-01', 1000, 100, 200, 10);
        $this->snapshot($org, $brand, $page, 'fake', '2026-09-15', 1100, 150, 300, 20);
        $this->snapshot($org, $brand, $page, 'fake', '2026-09-30', 1200, 50, 100, 5);
        $this->snapshot($org, $brand, $insta, 'instagram', '2026-09-10', 500);
        $this->snapshot($org, $brand, $insta, 'instagram', '2026-09-20', 450);

        $this->publishedPost($org, $brand, $page, 'fake', 'Lanzamiento', '2026-09-07 10:00', ['2026-09-09' => 100, '2026-09-12' => 120]);
        $this->publishedPost($org, $brand, $insta, 'instagram', 'Detrás de cámaras', '2026-09-09 18:00', ['2026-09-10' => 300]);
        // 23:30 del 30 de septiembre en la marca (1 de octubre en UTC): cuenta en septiembre.
        $this->publishedPost($org, $brand, $page, 'fake', 'Encuesta', '2026-09-30 23:30', ['2026-10-02' => 50]);
        // 23:00 del 31 de agosto en la marca: es del periodo anterior.
        $this->publishedPost($org, $brand, $page, 'fake', 'Agosto', '2026-08-31 23:00', ['2026-09-01' => 999]);
        $this->publishedPost($org, $brand, $page, 'fake', 'Fallida', null, [], TargetStatus::FAILED, '2026-09-20 09:00');
    }

    public function test_crear_listar_editar_y_borrar_un_informe(): void
    {
        [$owner, $org, $brand] = $this->scenario();

        $created = $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), $this->payload(['name' => "Informe\nmensual  de  Aurora"]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Informe mensual de Aurora')
            ->assertJsonPath('data.recipients', ['ana@cliente.com', 'luis@cliente.com'])
            ->assertJsonPath('data.period_label', 'Mes anterior')
            ->assertJsonPath('data.next_send_at', null)
            ->json('data');

        $this->getJson($this->url($brand))
            ->assertOk()
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.usage', ['used' => 1, 'limit' => 10])
            ->assertJsonPath('data.sections.5.value', 'best_times')
            ->assertJsonPath('data.sections.5.available', true)
            ->assertJsonPath('data.timezone', self::TZ);

        // Envío automático: el primer lunes a las 08:00 de la marca (14:00 UTC).
        $this->patchJson($this->url($brand, "/{$created['id']}"), $this->payload(['frequency' => 'weekly', 'send_day' => 1, 'send_hour' => 8]))
            ->assertOk()
            ->assertJsonPath('data.next_send_at', '2026-10-19T14:00:00+00:00');

        $this->deleteJson($this->url($brand, "/{$created['id']}"))->assertOk();
        $this->assertSame(0, ClientReport::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'report.created')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'report.updated')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'report.deleted')->count());
    }

    public function test_validaciones_del_informe(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $this->actingInOrganization($owner, $org);

        $this->postJson($this->url($brand), $this->payload(['recipients' => ['no-es-correo']]))
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.0');
        $this->postJson($this->url($brand), $this->payload(['recipients' => array_map(fn (int $i): string => "c{$i}@cliente.com", range(1, 11))]))
            ->assertUnprocessable()->assertJsonValidationErrors('recipients');
        $this->postJson($this->url($brand), $this->payload(['recipients' => [], 'frequency' => 'monthly', 'send_day' => 1]))
            ->assertUnprocessable()->assertJsonPath('errors.recipients.0', 'Añade al menos un destinatario para el envío automático.');
        $this->postJson($this->url($brand), $this->payload(['frequency' => 'monthly', 'send_day' => 31]))
            ->assertUnprocessable()->assertJsonValidationErrors('send_day');
        $this->postJson($this->url($brand), $this->payload(['frequency' => 'weekly', 'send_day' => 8]))
            ->assertUnprocessable()->assertJsonValidationErrors('send_day');
        $this->postJson($this->url($brand), $this->payload(['sections' => ['inventada']]))
            ->assertUnprocessable()->assertJsonValidationErrors('sections.0');
        $this->postJson($this->url($brand), $this->payload(['period' => 'siempre']))
            ->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->assertSame(0, ClientReport::query()->count());
    }

    public function test_plan_permisos_y_aislamiento(): void
    {
        [$owner, $org, $brand] = $this->scenario('starter');
        $this->actingInOrganization($owner, $org)->postJson($this->url($brand), $this->payload())->assertStatus(402);

        $this->setOrganizationPlan($org, 'professional');
        OrganizationEntitlementOverride::query()->create(['organization_id' => $org->id, 'entitlement_key' => Entitlement::CLIENT_REPORTS_MAX, 'value' => '1']);
        app(EntitlementsService::class)->flush();
        $id = $this->postJson($this->url($brand), $this->payload())->assertCreated()->json('data.id');
        $this->postJson($this->url($brand), $this->payload(['name' => 'Otro']))->assertStatus(402);

        // Quien sólo ve la analítica lista y descarga, pero no crea ni envía.
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);
        $this->actingInOrganization($viewer, $org)->getJson($this->url($brand))->assertOk()->assertJsonCount(1, 'data.reports');
        $this->postJson($this->url($brand, "/{$id}/runs"))->assertForbidden();
        $this->deleteJson($this->url($brand, "/{$id}"))->assertForbidden();
        $analyst = $this->addMember($org, OrganizationRole::ANALYST->value);
        $this->actingInOrganization($analyst, $org)->patchJson($this->url($brand, "/{$id}"), $this->payload(['name' => 'Renombrado']))->assertOk();

        // Otra organización no ve nada (ni con el id).
        [$ownerB, $orgB, $brandB] = $this->scenario('professional', 'otra@agencia.com');
        $this->actingInOrganization($ownerB, $orgB)->getJson($this->url($brandB))->assertOk()->assertJsonCount(0, 'data.reports');
        $this->postJson($this->url($brandB, "/{$id}/runs"))->assertNotFound();
        $this->getJson($this->url($brand))->assertNotFound();
    }

    public function test_genera_el_pdf_con_los_datos_del_periodo_en_la_zona_de_la_marca(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $this->seedSeptember($org, $brand);
        $id = $this->actingInOrganization($owner, $org)->postJson($this->url($brand), $this->payload())->json('data.id');

        $run = $this->postJson($this->url($brand, "/{$id}/runs"))
            ->assertStatus(202)
            ->assertJsonPath('data.period_from', '2026-09-01')
            ->assertJsonPath('data.period_to', '2026-09-30')
            ->assertJsonPath('data.trigger', 'manual')
            ->json('data');
        $this->assertSame('ready', $run['status']);
        $this->assertNotNull($run['download_url']);

        $data = $this->renderer->lastData();
        $this->assertSame(['summary', 'channels', 'growth', 'top_posts', 'publishing'], $data['sections']);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30, 'previous_from' => '2026-08-02', 'previous_to' => '2026-08-31'], $data['period']);

        // Resumen: 1.200 + 450 seguidores; +200 −50 = +150 sobre 1.500.
        $this->assertSame(1650, $data['summary']['followers']);
        $this->assertSame(150, $data['summary']['followers_change']);
        $this->assertEquals(10.0, $data['summary']['followers_change_pct']);
        $this->assertSame(['value' => 300, 'previous' => 100, 'change_pct' => 200.0], $data['summary']['reach']);
        $this->assertSame(['value' => 35, 'previous' => 70, 'change_pct' => -50.0], $data['summary']['engagement']);
        $this->assertSame(['value' => 3, 'previous' => 1, 'change_pct' => 200.0], $data['summary']['posts']);

        // Por red, con publicaciones por cuenta.
        $this->assertSame(['@cafeaurora', 'Página Aurora'], array_column($data['channels'], 'account'));
        $this->assertSame([450, 1200], array_column($data['channels'], 'followers'));
        $this->assertSame([1, 2], array_column($data['channels'], 'posts'));

        // Evolución arrastrando la última medición de cada cuenta.
        $growth = array_combine($data['growth']['dates'], $data['growth']['followers']);
        $this->assertCount(30, $growth);
        $this->assertSame(1000, $growth['2026-09-01']);
        $this->assertSame(1500, $growth['2026-09-10']);
        $this->assertSame(1550, $growth['2026-09-20']);
        $this->assertSame(1650, $growth['2026-09-30']);

        // Destacadas: por interacciones de su última medición.
        $this->assertSame(['Detrás de cámaras', 'Lanzamiento', 'Encuesta'], array_column($data['top_posts'], 'title'));
        $this->assertSame([300, 120, 50], array_column($data['top_posts'], 'engagement'));

        // Actividad: lunes 7 y miércoles 9 y 30; una fallida.
        $this->assertSame(3, $data['publishing']['published']);
        $this->assertSame(1, $data['publishing']['failed']);
        $this->assertSame([1, 0, 2, 0, 0, 0, 0], array_column($data['publishing']['by_weekday'], 'posts'));

        $stored = ClientReportRun::query()->sole();
        Storage::disk('local')->assertExists((string) $stored->file_path);
        $this->assertSame(1, AuditLog::query()->where('action', 'report.generated')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'report.sent')->count());
    }

    public function test_periodo_a_medida_y_secciones_que_el_plan_no_incluye(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $id = $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), $this->payload(['sections' => ['summary', 'best_times', 'competitors']]))
            ->json('data.id');
        OrganizationEntitlementOverride::query()->create(['organization_id' => $org->id, 'entitlement_key' => Entitlement::COMPETITOR_ACCOUNTS_MAX, 'value' => '0']);
        app(EntitlementsService::class)->flush();

        $this->postJson($this->url($brand, "/{$id}/runs"), ['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->postJson($this->url($brand, "/{$id}/runs"), ['period' => 'custom', 'from' => '2025-01-01', 'to' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson($this->url($brand, "/{$id}/runs"), ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-07'])
            ->assertStatus(202)
            ->assertJsonPath('data.period_from', '2026-10-01')
            ->assertJsonPath('data.period_to', '2026-10-07');

        // Sin competencia en el plan, esa sección no se pinta.
        $this->assertSame(['summary', 'best_times'], $this->renderer->lastData()['sections']);
        $this->assertFalse($this->renderer->lastData()['best_times']['sufficient']);
    }

    public function test_descarga_por_url_firmada(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $id = $this->actingInOrganization($owner, $org)->postJson($this->url($brand), $this->payload())->json('data.id');
        $url = (string) $this->postJson($this->url($brand, "/{$id}/runs"))->json('data.download_url');

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->streamedContent());
        $this->assertStringContainsString('informe-', (string) $response->headers->get('Content-Disposition'));

        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_enviar_ahora_un_correo_por_destinatario_sin_repetir(): void
    {
        Notification::fake();
        [$owner, $org, $brand] = $this->scenario();
        $this->seedSeptember($org, $brand);
        $id = $this->actingInOrganization($owner, $org)->postJson($this->url($brand), $this->payload())->json('data.id');

        $this->postJson($this->url($brand, "/{$id}/send"))
            ->assertStatus(202)
            ->assertJsonPath('data.trigger', 'send')
            ->assertJsonPath('data.delivered', 2);

        Notification::assertSentOnDemandTimes(ClientReportMail::class, 2);
        Notification::assertSentOnDemand(ClientReportMail::class, function (ClientReportMail $mail, array $channels, AnonymousNotifiable $notifiable) use ($brand): bool {
            $message = $mail->toMail($notifiable);

            return $notifiable->routes['mail'] === 'ana@cliente.com'
                && $message->subject === 'Informe mensual · Café Aurora · 1–30 de septiembre de 2026'
                && $message->replyTo === [['duena@agencia.com', null]]
                && count($message->rawAttachments) === 1
                && $message->rawAttachments[0]['name'] === "informe-{$brand->slug}-2026-09-01-2026-09-30.pdf";
        });

        $run = ClientReportRun::query()->sole();
        $this->assertNotNull($run->sent_at);
        $this->assertSame(['ana@cliente.com', 'luis@cliente.com'], $run->delivered_to);
        $this->assertNotNull(ClientReport::query()->sole()->last_sent_at);
        $audit = AuditLog::query()->where('action', 'report.sent')->sole();
        $this->assertSame(['ana@cliente.com', 'luis@cliente.com'], $audit->properties['recipients']);

        // Un reintento tras un envío a medias sólo escribe a quien faltaba.
        Notification::fake();
        $partial = ClientReportRun::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'client_report_id' => $run->client_report_id,
            'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'trigger' => RunTrigger::SEND,
            'status' => RunStatus::READY, 'data' => $run->data, 'file_path' => $run->file_path,
            'recipients' => ['ana@cliente.com', 'luis@cliente.com'], 'delivered_to' => ['ana@cliente.com'],
        ]);
        app(ReportGenerator::class)->process($partial);
        Notification::assertSentOnDemandTimes(ClientReportMail::class, 1);
        Notification::assertSentOnDemand(ClientReportMail::class, fn ($mail, $channels, AnonymousNotifiable $n): bool => $n->routes['mail'] === 'luis@cliente.com');
    }

    public function test_envio_programado_una_sola_vez_y_respetando_plan_y_marca(): void
    {
        Notification::fake();
        [$owner, $org, $brand] = $this->scenario();
        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), $this->payload(['period' => 'previous_week', 'frequency' => 'weekly', 'send_day' => 1, 'send_hour' => 8]))
            ->assertCreated();
        $report = ClientReport::query()->sole();
        $this->assertSame('2026-10-19 14:00:00', $report->next_send_at?->format('Y-m-d H:i:s'));

        // Aún no toca.
        $this->artisan('reports:send-due')->assertSuccessful();
        $this->assertSame(0, ClientReportRun::query()->count());

        // Lunes 19, 08:20 en la marca: sale el de la semana anterior (12–18).
        $this->travelTo(CarbonImmutable::parse('2026-10-19 14:20:00', 'UTC'));
        $this->artisan('reports:send-due')->assertSuccessful();
        $this->artisan('reports:send-due')->assertSuccessful();
        $run = ClientReportRun::query()->sole();
        $this->assertSame(RunTrigger::SCHEDULED, $run->trigger);
        $this->assertSame(['2026-10-12', '2026-10-18'], [$run->period_from->toDateString(), $run->period_to->toDateString()]);
        Notification::assertSentOnDemandTimes(ClientReportMail::class, 2);
        $this->assertSame('2026-10-26 14:00:00', $report->fresh()?->next_send_at?->format('Y-m-d H:i:s'));

        // Sin el plan, la ocurrencia se omite (y el calendario sigue).
        $this->setOrganizationPlan($org, 'starter');
        app(EntitlementsService::class)->flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 14:05:00', 'UTC'));
        $this->artisan('reports:send-due')->assertSuccessful();
        $this->assertSame(1, ClientReportRun::query()->count());
        $this->assertSame('2026-11-02 14:00:00', $report->fresh()?->next_send_at?->format('Y-m-d H:i:s'));

        // Marca eliminada: no se envía más.
        BrandDeleted::dispatch($brand);
        $this->assertNull($report->fresh()?->next_send_at);
    }

    public function test_retencion_y_generaciones_a_medias(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $id = $this->actingInOrganization($owner, $org)->postJson($this->url($brand), $this->payload())->json('data.id');
        $this->postJson($this->url($brand, "/{$id}/runs"))->assertStatus(202);
        $old = ClientReportRun::query()->sole();
        $old->forceFill(['created_at' => now()->subDays(401)])->save();
        $stuck = ClientReportRun::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'client_report_id' => $old->client_report_id,
            'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'trigger' => RunTrigger::MANUAL, 'status' => RunStatus::PENDING,
        ]);
        $stuck->forceFill(['created_at' => now()->subHours(2)])->save();

        $this->artisan('reports:prune')->assertSuccessful();

        $this->assertNull(ClientReportRun::query()->find($old->id));
        Storage::disk('local')->assertMissing((string) $old->file_path);
        $this->assertSame(RunStatus::FAILED, $stuck->fresh()?->status);
    }

    public function test_pdf_real_con_todas_las_secciones_y_marca_blanca(): void
    {
        [$owner, $org, $brand] = $this->scenario('agency');
        $this->seedSeptember($org, $brand);
        // Marca blanca con logo (PNG de 800 px: se reduce a 600 para el PDF).
        $image = imagecreatetruecolor(800, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 15, 118, 110));
        ob_start();
        imagepng($image);
        Storage::disk('local')->put('branding/logo.png', (string) ob_get_clean());
        $org->forceFill(['branding' => ['display_name' => 'Agencia Sol', 'primary_color' => '#0f766e', 'logo_path' => 'branding/logo.png']])->save();

        $logos = [];
        $real = new DompdfReportRenderer();
        $this->app->instance(ReportRenderer::class, new class ($real, $logos) implements ReportRenderer {
            /** @param list<string|null> $logos */
            public function __construct(private readonly ReportRenderer $inner, private array &$logos)
            {
            }

            public function render(array $data, ?string $logo): string
            {
                $this->logos[] = $logo;

                return $this->inner->render($data, $logo);
            }
        });

        $id = $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), $this->payload(['sections' => ['summary', 'channels', 'growth', 'top_posts', 'publishing', 'best_times', 'competitors']]))
            ->json('data.id');
        $this->postJson($this->url($brand, "/{$id}/runs"))->assertStatus(202)->assertJsonPath('data.status', 'ready');

        $run = ClientReportRun::query()->sole();
        $pdf = (string) Storage::disk('local')->get((string) $run->file_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        $this->assertSame('Agencia Sol', $run->data['branding']['name']);
        $this->assertTrue($run->data['branding']['white_label']);
        $this->assertCount(1, $logos);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $logos[0]);
        $size = getimagesizefromstring((string) base64_decode(substr((string) $logos[0], strlen('data:image/png;base64,'))));
        $this->assertSame(600, $size[0] ?? null);
    }

    public function test_la_plantilla_escapa_lo_que_escriben_las_personas(): void
    {
        $html = view('reports::pdf', [
            'report' => [
                'name' => '<b>Informe</b>',
                'brand' => ['name' => 'Café & Té', 'timezone' => self::TZ],
                'organization' => ['name' => 'Agencia'],
                'branding' => ['name' => 'Loop7', 'color' => 'red;}body{display:none', 'white_label' => false],
                'period' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30, 'previous_from' => '2026-08-02', 'previous_to' => '2026-08-31'],
                'generated_at' => '2026-10-15T15:00:00+00:00',
                'intro' => "<script>alert(1)</script>\nSegunda línea",
                'sections' => ['top_posts'],
                'top_posts' => [[
                    'title' => '<img src=x onerror=alert(1)>', 'provider' => 'fake', 'provider_label' => 'Red', 'account' => null,
                    'published_at' => '2026-09-07T10:00:00-06:00', 'url' => 'javascript:alert(1)',
                    'impressions' => 1, 'reach' => 1, 'likes' => 1, 'comments' => 0, 'shares' => 0, 'engagement' => 1,
                ]],
            ],
            'logo' => null,
            'color' => ReportFormat::color('red;}body{display:none'),
            'f' => new ReportFormat(),
            'growthChart' => null,
        ])->render();

        $this->assertStringContainsString('&lt;b&gt;Informe&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br />', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('display:none', $html);
        $this->assertStringContainsString('#4f46e5', $html);
        $this->assertStringContainsString('1–30 de septiembre de 2026', $html);
    }

    public function test_no_se_pide_dos_veces_a_la_vez_ni_se_envia_sin_destinatarios(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $id = $this->actingInOrganization($owner, $org)->postJson($this->url($brand), $this->payload(['recipients' => []]))->json('data.id');
        $this->postJson($this->url($brand, "/{$id}/send"))->assertUnprocessable()->assertJsonValidationErrors('recipients');

        ClientReportRun::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'client_report_id' => ClientReport::query()->sole()->id,
            'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'trigger' => RunTrigger::MANUAL, 'status' => RunStatus::PENDING,
        ]);
        $this->postJson($this->url($brand, "/{$id}/runs"))->assertUnprocessable()->assertJsonValidationErrors('report');
    }
}
