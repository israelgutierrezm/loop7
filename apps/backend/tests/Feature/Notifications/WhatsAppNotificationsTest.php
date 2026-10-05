<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+5215512345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Http::preventStrayRequests();
    }

    private function enableWhatsApp(): void
    {
        NotificationChannel::query()->create([
            'key' => NotificationChannel::WHATSAPP,
            'is_enabled' => true,
            'config' => [
                'phone_number_id' => '1098765432',
                'notice_template' => 'loop7_aviso',
                'verification_template' => 'loop7_codigo',
                'language' => 'es_MX',
            ],
            'credentials' => ['access_token' => 'EAAG-token-secreto'],
        ]);
    }

    private function fakeGraph(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);
    }

    /**
     * Organización con un plan que incluye avisos por WhatsApp.
     *
     * @return array{0: User, 1: Organization}
     */
    private function ownerWithWhatsAppPlan(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'professional');

        return [$owner, $org];
    }

    /**
     * Código enviado en la última petición a WhatsApp.
     */
    private function sentCode(): string
    {
        $code = null;
        Http::assertSent(function (Request $request) use (&$code): bool {
            if (($request['template']['name'] ?? null) === 'loop7_codigo') {
                $code = $request['template']['components'][0]['parameters'][0]['text'];
            }

            return true;
        });

        return (string) $code;
    }

    private function verify(User $owner, Organization $org): void
    {
        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/whatsapp', ['phone' => '+52 1 55 1234 5678'])->assertOk();
        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/whatsapp/verify', ['code' => $this->sentCode()])->assertOk();
    }

    private function notice(Organization $org, NotificationCategory $category = NotificationCategory::APPROVALS): OrganizationNotice
    {
        return new OrganizationNotice(
            organizationId: $org->id,
            kind: 'content.submitted',
            category: $category,
            title: 'Contenido por aprobar',
            body: "Ana envió «Lanzamiento»\na revisión.",
            path: '/app/content/abc',
        );
    }

    public function test_verificar_el_numero_con_codigo(): void
    {
        $this->enableWhatsApp();
        $this->fakeGraph();
        [$owner, $org] = $this->ownerWithWhatsAppPlan();

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertJsonPath('data.channels.whatsapp.available', true)
            ->assertJsonPath('data.channels.whatsapp.phone', null);

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp', ['phone' => '+52 1 55 1234 5678'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+52 ••• 5678');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/' . config('services.meta.graph_version') . '/1098765432/messages'
            && $r->hasHeader('Authorization', 'Bearer EAAG-token-secreto')
            && $r['to'] === '5215512345678'
            && $r['template']['name'] === 'loop7_codigo'
            && $r['template']['language']['code'] === 'es_MX'
            // Plantilla de autenticación: el código va en el cuerpo y en el botón «Copiar código».
            && $r['template']['components'][1]['sub_type'] === 'url'
            && $r['template']['components'][1]['parameters'][0]['text'] === $r['template']['components'][0]['parameters'][0]['text']);

        // Hasta confirmar el código, el número no se guarda.
        $this->assertNull($owner->fresh()->whatsapp_phone);

        $code = $this->sentCode();
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp/verify', ['code' => $wrong])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.phone', '+52 ••• 5678');

        $owner->refresh();
        $this->assertSame(self::PHONE, $owner->whatsapp_phone);
        $this->assertNotNull($owner->whatsapp_verified_at);
        // Cifrado en reposo y fuera de las respuestas.
        $raw = (string) DB::table('users')->where('id', $owner->id)->value('whatsapp_phone');
        $this->assertStringNotContainsString('5512345678', $raw);
        $this->assertArrayNotHasKey('whatsapp_phone', $owner->toArray());

        $audit = AuditLog::query()->where('action', 'notifications.whatsapp_verified')->sole();
        $this->assertSame('+52 ••• 5678', $audit->properties['phone']);

        // El código es de un solo uso.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp/verify', ['code' => $code])
            ->assertStatus(422);
    }

    public function test_intentos_limitados_por_codigo(): void
    {
        $this->enableWhatsApp();
        $this->fakeGraph();
        [$owner, $org] = $this->ownerWithWhatsAppPlan();
        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/whatsapp', ['phone' => self::PHONE])->assertOk();
        $code = $this->sentCode();
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/whatsapp/verify', ['code' => $wrong])->assertStatus(422);
        }

        // Agotados los intentos, ni el código correcto sirve.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp/verify', ['code' => $code])
            ->assertStatus(422);
        $this->assertNull($owner->fresh()->whatsapp_verified_at);
    }

    public function test_requiere_canal_configurado_plan_y_numero_valido(): void
    {
        $this->fakeGraph();
        [$owner, $org] = $this->createOwnerWithOrganization(); // prueba de Growth: sin WhatsApp

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp', ['phone' => self::PHONE])
            ->assertStatus(422);

        $this->enableWhatsApp();
        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertJsonPath('data.channels.whatsapp.available', false);
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp', ['phone' => self::PHONE])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->setOrganizationPlan($org, 'professional');
        foreach (['5512345678', '+0123456789', '+52 55', 'teléfono'] as $phone) {
            $this->travel(11)->minutes(); // fuera de la ventana del throttle de la ruta
            $this->actingInOrganization($owner, $org)
                ->postJson('/api/v1/me/whatsapp', ['phone' => $phone])
                ->assertStatus(422)->assertJsonValidationErrors('phone');
        }

        Http::assertNothingSent();
    }

    public function test_si_whatsapp_rechaza_el_envio_se_informa_sin_detalles(): void
    {
        $this->enableWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist', 'code' => 132001]], 400)]);
        [$owner, $org] = $this->ownerWithWhatsAppPlan();

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp', ['phone' => self::PHONE])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone')
            ->assertDontSee('Template name');
    }

    public function test_limite_diario_de_codigos_por_numero(): void
    {
        $this->enableWhatsApp();
        $this->fakeGraph();
        [$owner, $org] = $this->ownerWithWhatsAppPlan();

        for ($i = 0; $i < 5; $i++) {
            $this->travel(11)->minutes(); // fuera de la ventana del throttle de la ruta
            $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/whatsapp', ['phone' => self::PHONE])->assertOk();
        }
        $this->travel(11)->minutes();
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/whatsapp', ['phone' => self::PHONE])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        Http::assertSentCount(5);
    }

    public function test_el_limite_de_la_ruta_no_lo_consumen_otras_rutas(): void
    {
        $this->enableWhatsApp();
        $this->fakeGraph();
        [$owner, $org] = $this->ownerWithWhatsAppPlan();

        // Sin prefijo, los throttle numéricos comparten un contador por usuario.
        for ($i = 0; $i < 4; $i++) {
            $this->actingInOrganization($owner, $org)
                ->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])
                ->assertOk();
        }

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/whatsapp', ['phone' => self::PHONE])->assertOk();
    }

    public function test_el_aviso_sale_por_whatsapp_con_la_plantilla_de_avisos(): void
    {
        $this->enableWhatsApp();
        $this->fakeGraph();
        [$owner, $org] = $this->ownerWithWhatsAppPlan();
        $notice = $this->notice($org);

        // Sin número verificado no hay canal.
        $this->assertNotContains(WhatsAppChannel::class, $notice->via($owner));

        $this->verify($owner, $org);
        $owner->refresh();
        $this->assertContains(WhatsAppChannel::class, $notice->via($owner));
        // Por defecto sólo lo urgente: la facturación no.
        $this->assertNotContains(WhatsAppChannel::class, $this->notice($org, NotificationCategory::BILLING)->via($owner));

        $owner->notify($notice);

        Http::assertSent(fn (Request $r) => ($r['template']['name'] ?? null) === 'loop7_aviso'
            && $r['to'] === '5215512345678'
            && $r['template']['components'][0]['parameters'][0]['text'] === $org->name
            && $r['template']['components'][0]['parameters'][1]['text'] === 'Contenido por aprobar'
            // Meta no admite saltos de línea en las variables.
            && $r['template']['components'][0]['parameters'][2]['text'] === 'Ana envió «Lanzamiento» a revisión.'
            && count($r['template']['components']) === 1);
    }

    public function test_el_plan_de_la_organizacion_del_aviso_decide(): void
    {
        $this->enableWhatsApp();
        $this->fakeGraph();
        [$owner, $org] = $this->ownerWithWhatsAppPlan();
        $this->verify($owner, $org);

        // El mismo usuario en otra organización sin WhatsApp en su plan.
        [, $growth] = $this->createOwnerWithOrganization(orgName: 'Sin WhatsApp');
        $growth->users()->attach($owner->id, ['status' => 'active', 'all_brands_access' => true, 'joined_at' => now()]);

        $this->assertContains(WhatsAppChannel::class, $this->notice($org)->via($owner->refresh()));
        $this->assertNotContains(WhatsAppChannel::class, $this->notice($growth)->via($owner));

        // Preferencia desactivada.
        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/me/notification-preferences', ['whatsapp' => ['approvals' => false]])
            ->assertOk();
        $this->assertNotContains(WhatsAppChannel::class, $this->notice($org)->via($owner->refresh()));
    }

    public function test_un_fallo_de_whatsapp_no_rompe_el_aviso_ni_expone_el_numero(): void
    {
        $this->enableWhatsApp();
        // El código llega; el aviso posterior falla (el primer stub que coincide gana).
        Http::fake(fn (Request $r) => ($r['template']['name'] ?? null) === 'loop7_codigo'
            ? Http::response(['messages' => [['id' => 'wamid.1']]])
            : Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401));
        [$owner, $org] = $this->ownerWithWhatsAppPlan();
        $this->verify($owner, $org);

        Log::spy();
        $owner->refresh()->notify($this->notice($org));

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id, 'type' => 'content.submitted']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'WhatsApp:')
            && $context['user_id'] === $owner->id
            && str_contains($context['error'], 'token')
            && ! str_contains((string) json_encode($context), '5512345678')
            && ! str_contains((string) json_encode($context), 'EAAG'));
    }

    public function test_quitar_el_numero(): void
    {
        $this->enableWhatsApp();
        $this->fakeGraph();
        [$owner, $org] = $this->ownerWithWhatsAppPlan();
        $this->verify($owner, $org);

        $this->actingInOrganization($owner, $org)->deleteJson('/api/v1/me/whatsapp')->assertOk()->assertJsonPath('data.phone', null);

        $owner->refresh();
        $this->assertNull($owner->whatsapp_phone);
        $this->assertNull($owner->whatsapp_verified_at);
        $this->assertTrue(AuditLog::query()->where('action', 'notifications.whatsapp_removed')->exists());
        $this->assertNotContains(WhatsAppChannel::class, $this->notice($org)->via($owner));
    }
}
