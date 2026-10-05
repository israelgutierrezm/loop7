<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Push\WebPushGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Http::preventStrayRequests();
        $this->admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($this->admin);

        $this->app->instance(WebPushGateway::class, new class () implements WebPushGateway {
            private int $generated = 0;

            public function send(array $subscriptions, string $payload, array $vapid): array
            {
                return ['delivered' => count($subscriptions), 'expired' => []];
            }

            public function createKeys(): array
            {
                $this->generated++;

                return ['public_key' => "BPublica{$this->generated}", 'private_key' => "privada-{$this->generated}"];
            }
        });
    }

    private function whatsAppPayload(array $overrides = []): array
    {
        return [
            'is_enabled' => true,
            'phone_number_id' => '1098765432',
            'access_token' => 'EAAG-token-secreto-1234',
            'notice_template' => 'loop7_aviso',
            'verification_template' => 'loop7_codigo',
            'language' => 'es_MX',
            ...$overrides,
        ];
    }

    public function test_solo_superadmin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/notification-channels')->assertForbidden();
        $this->postJson('/api/v1/platform/notification-channels/webpush/keys')->assertForbidden();
        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload())->assertForbidden();
        $this->postJson('/api/v1/platform/notification-channels/whatsapp/test')->assertForbidden();
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_claves_vapid_y_activacion_del_push(): void
    {
        $this->getJson('/api/v1/platform/notification-channels')
            ->assertOk()
            ->assertJsonPath('data.webpush.is_enabled', false)
            ->assertJsonPath('data.webpush.public_key', null);

        // No se puede activar sin claves.
        $this->putJson('/api/v1/platform/notification-channels/webpush', ['is_enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('is_enabled');

        $this->postJson('/api/v1/platform/notification-channels/webpush/keys')
            ->assertOk()
            ->assertJsonPath('data.webpush.public_key', 'BPublica1')
            ->assertJsonMissingPath('data.webpush.private_key');

        $this->putJson('/api/v1/platform/notification-channels/webpush', ['is_enabled' => true, 'subject' => 'https://evil'])
            ->assertOk(); // URL https válida
        $this->putJson('/api/v1/platform/notification-channels/webpush', ['is_enabled' => true, 'subject' => 'javascript:alert(1)'])
            ->assertStatus(422)->assertJsonValidationErrors('subject');
        $this->putJson('/api/v1/platform/notification-channels/webpush', ['is_enabled' => true, 'subject' => 'mailto:soporte@loop7.test'])
            ->assertOk()
            ->assertJsonPath('data.webpush.ready', true)
            ->assertJsonPath('data.webpush.subject', 'mailto:soporte@loop7.test');

        $channel = NotificationChannel::query()->where('key', NotificationChannel::WEB_PUSH)->sole();
        $this->assertSame('privada-1', $channel->secret('private_key'));
        // Cifrada en reposo.
        $raw = (string) DB::table('notification_channels')->where('key', 'webpush')->value('credentials');
        $this->assertStringNotContainsString('privada-1', $raw);
        $this->assertTrue(AuditLog::query()->where('action', 'platform.notification_channel_updated')->exists());
    }

    public function test_regenerar_claves_desactiva_los_navegadores_registrados(): void
    {
        $this->postJson('/api/v1/platform/notification-channels/webpush/keys')->assertOk();
        PushSubscription::query()->create([
            'user_id' => $this->admin->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/x',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/x'),
            'public_key' => 'p', 'auth_token' => 'a',
        ]);

        $this->postJson('/api/v1/platform/notification-channels/webpush/keys')
            ->assertOk()
            ->assertJsonPath('data.webpush.public_key', 'BPublica2')
            ->assertJsonPath('data.webpush.subscriptions', 0);

        $this->assertSame(0, PushSubscription::query()->count());
        $audit = AuditLog::query()->where('action', 'platform.push_keys_generated')->latest('id')->first();
        $this->assertSame(1, $audit?->properties['subscriptions_removed']);
    }

    public function test_si_openssl_no_puede_crear_claves_se_explica(): void
    {
        $this->app->instance(WebPushGateway::class, new class () implements WebPushGateway {
            public function send(array $subscriptions, string $payload, array $vapid): array
            {
                return ['delivered' => 0, 'expired' => []];
            }

            public function createKeys(): array
            {
                throw new \RuntimeException('Unable to create the key');
            }
        });

        $this->postJson('/api/v1/platform/notification-channels/webpush/keys')
            ->assertStatus(422)
            ->assertJsonPath('code', 'push_keys_failed');
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_configurar_whatsapp_con_token_de_solo_escritura(): void
    {
        // Para activarlo hace falta todo.
        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload(['verification_template' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('verification_template');

        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload())
            ->assertOk()
            ->assertJsonPath('data.whatsapp.ready', true)
            ->assertJsonPath('data.whatsapp.access_token', '••••1234')
            ->assertDontSee('EAAG-token-secreto');

        // Sin token se conserva el guardado.
        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload(['access_token' => null, 'notice_template' => 'otro_aviso']))
            ->assertOk()
            ->assertJsonPath('data.whatsapp.notice_template', 'otro_aviso')
            ->assertJsonPath('data.whatsapp.access_token', '••••1234');

        $channel = NotificationChannel::query()->where('key', NotificationChannel::WHATSAPP)->sole();
        $this->assertSame('EAAG-token-secreto-1234', $channel->secret('access_token'));
        $raw = (string) DB::table('notification_channels')->where('key', 'whatsapp')->value('credentials');
        $this->assertStringNotContainsString('EAAG', $raw);

        // El token nunca llega a la auditoría.
        foreach (AuditLog::query()->where('action', 'platform.notification_channel_updated')->get() as $log) {
            $this->assertStringNotContainsString('EAAG', (string) json_encode($log->properties));
        }

        // Validación de formato: el id va en la URL de la API.
        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload(['phone_number_id' => '../me']))
            ->assertStatus(422)->assertJsonValidationErrors('phone_number_id');
        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload(['notice_template' => 'Aviso Loop7']))
            ->assertStatus(422)->assertJsonValidationErrors('notice_template');
        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload(['language' => 'español']))
            ->assertStatus(422)->assertJsonValidationErrors('language');
    }

    public function test_probar_conexion_de_whatsapp(): void
    {
        $this->postJson('/api/v1/platform/notification-channels/whatsapp/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false);

        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload())->assertOk();
        Http::fake([
            'graph.facebook.com/*/1098765432/messages' => Http::response(['messages' => [['id' => 'wamid.1']]]),
            'graph.facebook.com/*' => Http::response(['display_phone_number' => '+52 55 0000 0000', 'verified_name' => 'Loop7', 'quality_rating' => 'GREEN']),
        ]);

        $this->postJson('/api/v1/platform/notification-channels/whatsapp/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.message', 'Conexión correcta: Loop7 (+52 55 0000 0000).');
        Http::assertSentCount(1);

        // Con destino: además envía un aviso de prueba con la plantilla.
        $this->postJson('/api/v1/platform/notification-channels/whatsapp/test', ['to' => '+52 55 1234 5678'])
            ->assertOk()
            ->assertJsonPath('data.ok', true);
        Http::assertSent(fn (Request $r) => ($r['template']['name'] ?? null) === 'loop7_aviso' && $r['to'] === '525512345678');

        $this->postJson('/api/v1/platform/notification-channels/whatsapp/test', ['to' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_token_caducado_se_explica(): void
    {
        $this->putJson('/api/v1/platform/notification-channels/whatsapp', $this->whatsAppPayload())->assertOk();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 401)]);

        $this->postJson('/api/v1/platform/notification-channels/whatsapp/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'El token de acceso de WhatsApp no es válido o caducó.');
    }
}
