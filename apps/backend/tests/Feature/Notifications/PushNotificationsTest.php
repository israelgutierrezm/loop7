<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Channels\WebPushChannel;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Notifications\Push\PushEndpoint;
use App\Modules\Notifications\Push\WebPushGateway;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

    /** @var object{sent: list<array{endpoints: list<string>, payload: array<string, mixed>, vapid: array<string, string>}>, expire: list<string>} */
    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->gateway = new class () implements WebPushGateway {
            /** @var list<array{endpoints: list<string>, payload: array<string, mixed>, vapid: array<string, string>}> */
            public array $sent = [];

            /** @var list<string> */
            public array $expire = [];

            public function send(array $subscriptions, string $payload, array $vapid): array
            {
                $endpoints = array_map(fn (PushSubscription $s) => $s->endpoint, $subscriptions);
                $this->sent[] = ['endpoints' => $endpoints, 'payload' => json_decode($payload, true), 'vapid' => $vapid];
                $expired = array_values(array_intersect($endpoints, $this->expire));

                return ['delivered' => count($endpoints) - count($expired), 'expired' => $expired];
            }

            public function createKeys(): array
            {
                return ['public_key' => 'BPublica' . bin2hex(random_bytes(4)), 'private_key' => 'privada-' . bin2hex(random_bytes(4))];
            }
        };
        $this->app->instance(WebPushGateway::class, $this->gateway);
    }

    private function enablePush(): void
    {
        NotificationChannel::query()->create([
            'key' => NotificationChannel::WEB_PUSH,
            'is_enabled' => true,
            'config' => ['public_key' => 'BClavePublicaVapid'],
            'credentials' => ['private_key' => 'clave-privada-vapid'],
        ]);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function subscription(string $endpoint = self::FCM): array
    {
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $b64("\x04" . random_bytes(64)), 'auth' => $b64(random_bytes(16))]];
    }

    private function notice(Organization $org, NotificationCategory $category = NotificationCategory::PUBLISHING, bool $mailable = true): OrganizationNotice
    {
        return new OrganizationNotice(
            organizationId: $org->id,
            kind: 'content.publish_failed',
            category: $category,
            title: 'No se pudo publicar',
            body: '«Lanzamiento» falló en Instagram.',
            path: '/app/content/abc',
            level: 'danger',
            mailable: $mailable,
        );
    }

    public function test_solo_se_aceptan_servicios_push_conocidos(): void
    {
        $this->assertTrue(PushEndpoint::allowed('https://fcm.googleapis.com/fcm/send/x'));
        $this->assertTrue(PushEndpoint::allowed('https://updates.push.services.mozilla.com/wpush/v2/x'));
        $this->assertTrue(PushEndpoint::allowed('https://web.push.apple.com/QGx'));
        $this->assertTrue(PushEndpoint::allowed('https://wns2-par02p.notify.windows.com/w/?token=x'));

        $this->assertFalse(PushEndpoint::allowed('http://fcm.googleapis.com/fcm/send/x'), 'sin https');
        $this->assertFalse(PushEndpoint::allowed('https://169.254.169.254/latest/meta-data'), 'red interna');
        $this->assertFalse(PushEndpoint::allowed('https://fcm.googleapis.com.evil.test/x'), 'sufijo falso');
        $this->assertFalse(PushEndpoint::allowed('https://evil.test/?x=.push.apple.com'));
        $this->assertFalse(PushEndpoint::allowed('https://user:pass@fcm.googleapis.com/x'), 'credenciales en la URL');
        $this->assertFalse(PushEndpoint::allowed('https://fcm.googleapis.com:8443/x'), 'otro puerto');
    }

    public function test_activar_push_en_un_navegador(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        // Sin claves VAPID configuradas no hay push.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/push-subscriptions', $this->subscription())
            ->assertStatus(422);

        $this->enablePush();
        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.channels.push.available', true)
            ->assertJsonPath('data.channels.push.public_key', 'BClavePublicaVapid')
            ->assertJsonPath('data.channels.push.devices', 0)
            ->assertJsonMissingPath('data.channels.push.private_key');

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/push-subscriptions', $this->subscription())
            ->assertCreated()
            ->assertJsonPath('data.devices', 1);

        // El mismo navegador otra vez no duplica.
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/push-subscriptions', $this->subscription())
            ->assertCreated()
            ->assertJsonPath('data.devices', 1);

        $stored = PushSubscription::query()->sole();
        $this->assertSame($owner->id, $stored->user_id);
        $this->assertArrayNotHasKey('auth_token', $stored->toArray());
    }

    public function test_rechaza_endpoints_y_claves_no_validos(): void
    {
        $this->enablePush();
        [$owner, $org] = $this->createOwnerWithOrganization();
        $client = $this->actingInOrganization($owner, $org);

        $client->postJson('/api/v1/me/push-subscriptions', $this->subscription('https://intranet.local/push'))
            ->assertStatus(422)->assertJsonValidationErrors('endpoint');
        $client->postJson('/api/v1/me/push-subscriptions', $this->subscription('http://127.0.0.1:6379/'))
            ->assertStatus(422)->assertJsonValidationErrors('endpoint');

        $bad = $this->subscription();
        $bad['keys']['p256dh'] = 'no-es-una-clave';
        $client->postJson('/api/v1/me/push-subscriptions', $bad)->assertStatus(422)->assertJsonValidationErrors('keys.p256dh');

        $bad = $this->subscription();
        $bad['keys']['auth'] = rtrim(strtr(base64_encode(random_bytes(8)), '+/', '-_'), '=');
        $client->postJson('/api/v1/me/push-subscriptions', $bad)->assertStatus(422)->assertJsonValidationErrors('keys.auth');

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_el_navegador_pasa_a_la_cuenta_que_lo_registra_y_cada_quien_borra_solo_los_suyos(): void
    {
        $this->enablePush();
        [$owner, $org] = $this->createOwnerWithOrganization();
        [$other, $otherOrg] = $this->createOwnerWithOrganization(orgName: 'Otra');

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/push-subscriptions', $this->subscription())->assertCreated();
        $this->actingInOrganization($other, $otherOrg)->postJson('/api/v1/me/push-subscriptions', $this->subscription())->assertCreated();
        $this->assertSame($other->id, PushSubscription::query()->sole()->user_id);

        // Quien ya no es dueño del navegador no lo puede borrar.
        $this->actingInOrganization($owner, $org)
            ->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => self::FCM])
            ->assertOk();
        $this->assertSame(1, PushSubscription::query()->count());

        $this->actingInOrganization($other, $otherOrg)
            ->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => self::FCM])
            ->assertOk()
            ->assertJsonPath('data.devices', 0);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_limite_de_navegadores_por_usuario(): void
    {
        $this->enablePush();
        [$owner, $org] = $this->createOwnerWithOrganization();

        for ($i = 1; $i <= 11; $i++) {
            $this->travel(1)->minutes();
            $this->actingInOrganization($owner, $org)
                ->postJson('/api/v1/me/push-subscriptions', $this->subscription(self::FCM . $i))
                ->assertCreated();
        }

        $this->assertSame(10, $owner->pushSubscriptions()->count());
        $this->assertFalse($owner->pushSubscriptions()->where('endpoint', self::FCM . '1')->exists(), 'se olvida el más antiguo');
    }

    public function test_el_aviso_sale_por_push_segun_las_preferencias(): void
    {
        $this->enablePush();
        [$owner, $org] = $this->createOwnerWithOrganization();
        $notice = $this->notice($org);

        // Sin navegadores registrados no hay canal push.
        $this->assertNotContains(WebPushChannel::class, $notice->via($owner));

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/push-subscriptions', $this->subscription())->assertCreated();
        $this->assertContains(WebPushChannel::class, $notice->via($owner));

        // Los avisos que no merecen salir de la app tampoco van por push.
        $this->assertNotContains(WebPushChannel::class, $this->notice($org, mailable: false)->via($owner));
        // Por defecto, como el correo: las conversaciones asignadas no.
        $this->assertNotContains(WebPushChannel::class, $this->notice($org, NotificationCategory::INBOX)->via($owner));

        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/me/notification-preferences', ['push' => ['publishing' => false, 'inbox' => true]])
            ->assertOk()
            ->assertJsonPath('data.categories.1.key', 'publishing')
            ->assertJsonPath('data.categories.1.push', false)
            ->assertJsonPath('data.categories.1.mail', true);
        $owner->refresh();
        $this->assertNotContains(WebPushChannel::class, $notice->via($owner));
        $this->assertContains('mail', $notice->via($owner), 'el correo no cambia');
        $this->assertContains(WebPushChannel::class, $this->notice($org, NotificationCategory::INBOX)->via($owner));

        // Si SUPERADMIN desactiva el canal, deja de enviarse.
        $owner->forceFill(['notification_preferences' => null])->save();
        NotificationChannel::query()->where('key', NotificationChannel::WEB_PUSH)->update(['is_enabled' => false]);
        $this->assertNotContains(WebPushChannel::class, $notice->via($owner->refresh()));
    }

    public function test_envio_push_con_ruta_de_la_organizacion_y_limpieza_de_caducados(): void
    {
        $this->enablePush();
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/push-subscriptions', $this->subscription())->assertCreated();
        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/push-subscriptions', $this->subscription(self::FCM . 'viejo'))->assertCreated();
        $this->gateway->expire = [self::FCM . 'viejo'];

        $owner->notify($this->notice($org));

        $this->assertCount(1, $this->gateway->sent);
        $sent = $this->gateway->sent[0];
        $this->assertEqualsCanonicalizing([self::FCM, self::FCM . 'viejo'], $sent['endpoints']);
        $this->assertSame('No se pudo publicar', $sent['payload']['title']);
        $this->assertStringContainsString($org->name, $sent['payload']['body']);
        // Ruta relativa: el service worker la abre en su propio origen.
        $this->assertSame("/app/content/abc?org={$org->public_id}", $sent['payload']['path']);
        $this->assertSame('content.publish_failed', $sent['payload']['tag']);
        $this->assertSame('clave-privada-vapid', $sent['vapid']['private_key']);
        $this->assertStringStartsWith('mailto:', $sent['vapid']['subject']);

        // El navegador que ya no existe se olvida; el otro queda marcado como usado.
        $this->assertSame([self::FCM], $owner->pushSubscriptions()->pluck('endpoint')->all());
        $this->assertNotNull($owner->pushSubscriptions()->sole()->last_used_at);
    }

    public function test_un_fallo_del_servicio_push_no_rompe_el_aviso(): void
    {
        $this->enablePush();
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/push-subscriptions', $this->subscription())->assertCreated();
        $this->app->instance(WebPushGateway::class, new class () implements WebPushGateway {
            public function send(array $subscriptions, string $payload, array $vapid): array
            {
                throw new \ErrorException('clave inválida');
            }

            public function createKeys(): array
            {
                return ['public_key' => '', 'private_key' => ''];
            }
        });

        $owner->notify($this->notice($org));

        // El aviso queda en la campana aunque el push falle.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id, 'type' => 'content.publish_failed']);
    }

    public function test_aviso_de_prueba(): void
    {
        $this->enablePush();
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/push-subscriptions/test')->assertStatus(422);

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/me/push-subscriptions', $this->subscription())->assertCreated();
        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/me/push-subscriptions/test')
            ->assertOk()
            ->assertJsonPath('data.delivered', 1);
        $this->assertSame('push.test', $this->gateway->sent[0]['payload']['tag']);
    }

    public function test_las_preferencias_admiten_varios_canales_a_la_vez(): void
    {
        Notification::fake();
        [$owner, $org] = $this->createOwnerWithOrganization();
        $client = $this->actingInOrganization($owner, $org);

        $client->putJson('/api/v1/me/notification-preferences', [])->assertStatus(422);
        $client->putJson('/api/v1/me/notification-preferences', ['push' => ['billing' => 'tal vez']])->assertStatus(422);
        $client->putJson('/api/v1/me/notification-preferences', [
            'mail' => ['approvals' => false],
            'push' => ['approvals' => true],
            'whatsapp' => ['approvals' => false, 'desconocida' => true],
        ])->assertOk()
            ->assertJsonPath('data.categories.0.mail', false)
            ->assertJsonPath('data.categories.0.push', true)
            ->assertJsonPath('data.categories.0.whatsapp', false);

        $prefs = User::query()->findOrFail($owner->id)->notification_preferences;
        $this->assertArrayNotHasKey('desconocida', $prefs['whatsapp'] ?? []);
    }
}
