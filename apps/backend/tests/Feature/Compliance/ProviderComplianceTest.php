<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Events\SocialConnectionExpired;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Requisitos de las redes para aprobar sus apps (docs/06): callbacks de Meta
 * para Threads, revocar el acceso al desconectar y webhooks de TikTok.
 */
class ProviderComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Http::preventStrayRequests();
    }

    private function configure(string $provider, string $clientId, string $secret): void
    {
        $record = SocialProvider::query()->where('key', $provider)->firstOrFail();
        $record->is_enabled = true;
        $record->credentials = ['client_id' => $clientId, 'client_secret' => $secret];
        $record->save();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Brand}
     */
    private function organization(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        return [$owner, $org, Brand::factory()->create(['organization_id' => $org->id])];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connection(Organization $org, Brand $brand, string $provider, string $accountId, array $attributes = []): SocialConnection
    {
        return SocialConnection::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'provider' => $provider,
            'status' => 'connected',
            'external_account_id' => $accountId,
            'external_account_name' => 'Cuenta',
            'access_token' => 'ACCESS',
            'refresh_token' => 'REFRESH',
            ...$attributes,
        ]);
    }

    private function signedRequest(string $userId, string $secret): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['user_id' => $userId, 'algorithm' => 'HMAC-SHA256', 'issued_at' => time()])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');

        return $signature . '.' . $payload;
    }

    // --- Meta: callbacks de la app de Threads ---

    public function test_borrado_de_datos_de_threads_con_su_secreto_y_solo_en_threads(): void
    {
        $this->configure('threads', 'TAPP', 'threads-secret');
        $this->configure('facebook', 'FAPP', 'facebook-secret');
        [, $org, $brand] = $this->organization();
        $threads = $this->connection($org, $brand, 'threads', '1789');
        $facebook = $this->connection($org, $brand, 'facebook', '1789'); // mismo id, otra red

        // Firmado con el secreto de Facebook: no vale para Threads.
        $this->postJson('/api/v1/data-deletion/threads', ['signed_request' => $this->signedRequest('1789', 'facebook-secret')])
            ->assertStatus(400);

        $response = $this->postJson('/api/v1/data-deletion/threads', ['signed_request' => $this->signedRequest('1789', 'threads-secret')])
            ->assertOk();

        $this->assertNotEmpty($response->json('confirmation_code'));
        $this->assertDatabaseMissing('social_connections', ['id' => $threads->id]);
        $this->assertDatabaseHas('social_connections', ['id' => $facebook->id]);
        $this->assertDatabaseHas('data_deletion_requests', ['provider' => 'threads', 'external_user_id' => '1789', 'status' => 'completed']);
    }

    public function test_desautorizar_desde_threads_deja_la_conexion_expirada_sin_tokens(): void
    {
        Event::fake([SocialConnectionExpired::class]);
        $this->configure('threads', 'TAPP', 'threads-secret');
        [, $org, $brand] = $this->organization();
        $threads = $this->connection($org, $brand, 'threads', '1789');

        $this->postJson('/api/v1/deauthorize/threads', ['signed_request' => $this->signedRequest('1789', 'threads-secret')])
            ->assertOk()
            ->assertJsonPath('connections', 1);

        $threads->refresh();
        $this->assertSame('expired', $threads->status->value);
        $this->assertNull($threads->access_token);
        Event::assertDispatched(SocialConnectionExpired::class);

        $this->postJson('/api/v1/deauthorize/threads', ['signed_request' => 'firma.mala'])->assertStatus(400);
        $this->postJson('/api/v1/deauthorize/tiktok', ['signed_request' => 'x.y'])->assertNotFound();
    }

    // --- Revocar el acceso al desconectar ---

    public function test_desconectar_youtube_revoca_el_token_en_google(): void
    {
        $this->configure('youtube', 'GID', 'GSECRET');
        [$owner, $org, $brand] = $this->organization();
        $connection = $this->connection($org, $brand, 'youtube', 'UC1');
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

        $this->actingInOrganization($owner, $org)
            ->deleteJson("/api/v1/social/connections/{$connection->public_id}")
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'REFRESH' && ! isset($r['client_secret']));
        $log = DB::table('audit_logs')->where('action', 'social.disconnected')->first();
        $this->assertStringContainsString('"revoked_remotely":true', (string) $log?->properties);
    }

    public function test_no_revoca_si_otra_marca_usa_la_misma_cuenta_ni_bloquea_si_falla(): void
    {
        $this->configure('x', 'XID', 'XSECRET');
        [$owner, $org, $brand] = $this->organization();
        $otherBrand = Brand::factory()->create(['organization_id' => $org->id]);
        $shared = $this->connection($org, $brand, 'x', 'U1');
        $this->connection($org, $otherBrand, 'x', 'U1');
        Http::fake(['api.x.com/2/oauth2/revoke' => Http::response(['error' => 'invalid_request'], 400)]);

        // La otra marca sigue usando la cuenta: no se revoca en X.
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/social/connections/{$shared->public_id}")->assertOk();
        Http::assertNothingSent();

        // Ya sin otra conexión: se revoca (con Basic) y un fallo de X no impide desconectar.
        $last = SocialConnection::query()->where('brand_id', $otherBrand->id)->firstOrFail();
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/social/connections/{$last->public_id}")->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/2/oauth2/revoke')
            && $r->hasHeader('Authorization', 'Basic ' . base64_encode('XID:XSECRET')) && $r['token'] === 'REFRESH');
        $this->assertSoftDeleted('social_connections', ['id' => $last->id]);
    }

    public function test_tiktok_revoca_con_un_token_de_acceso_vigente(): void
    {
        $this->configure('tiktok', 'CKEY', 'CSECRET');
        [$owner, $org, $brand] = $this->organization();
        $connection = $this->connection($org, $brand, 'tiktok', 'OID', ['token_expires_at' => now()->subHour()]);
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => 'act.NEW', 'refresh_token' => 'rft.NEW', 'expires_in' => 86400]),
            'open.tiktokapis.com/v2/oauth/revoke/' => Http::response(['error' => ['code' => 'ok']]),
        ]);

        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/social/connections/{$connection->public_id}")->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/revoke/') && $r['token'] === 'act.NEW' && $r['client_key'] === 'CKEY');
    }

    // --- Webhooks de TikTok ---

    /**
     * @param  array<string, mixed>  $content
     */
    private function tiktokWebhook(string $event, array $content = [], string $secret = 'CSECRET', string $clientKey = 'CKEY'): \Illuminate\Testing\TestResponse
    {
        $body = (string) json_encode([
            'client_key' => $clientKey,
            'event' => $event,
            'create_time' => time(),
            'user_openid' => 'OID',
            'content' => json_encode($content),
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return $this->call('POST', '/api/v1/social/webhooks/tiktok', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_TIKTOK_SIGNATURE' => "t={$timestamp},s={$signature}",
        ], $body);
    }

    public function test_webhook_de_tiktok_firma_revocacion_e_idempotencia(): void
    {
        Event::fake([SocialConnectionExpired::class]);
        $this->configure('tiktok', 'CKEY', 'CSECRET');
        [, $org, $brand] = $this->organization();
        $connection = $this->connection($org, $brand, 'tiktok', 'OID');

        $this->tiktokWebhook('authorization.removed', ['reason' => 1], secret: 'OTRO')->assertStatus(401);
        $this->tiktokWebhook('authorization.removed', ['reason' => 1], clientKey: 'OTRA_APP')->assertStatus(400);
        $this->assertSame('connected', $connection->fresh()->status->value);

        $this->tiktokWebhook('authorization.removed', ['reason' => 1])->assertOk()->assertJsonPath('received', true);
        $this->assertSame('expired', $connection->fresh()->status->value);
        $this->assertNull($connection->fresh()->access_token);
        Event::assertDispatchedTimes(SocialConnectionExpired::class, 1);
    }

    public function test_webhook_de_tiktok_video_publico_guarda_su_id_y_enlace(): void
    {
        $this->configure('tiktok', 'CKEY', 'CSECRET');
        [, $org, $brand] = $this->organization();
        $connection = $this->connection($org, $brand, 'tiktok', 'OID');
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id, 'external_id' => 'OID', 'name' => 'Café', 'type' => 'profile',
        ]);
        $content = ContentItem::query()->create(['organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Video', 'status' => 'published']);
        $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => 'tiktok', 'body' => 'x', 'format' => 'video']);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id, 'post_variant_id' => $variant->id, 'social_connection_destination_id' => $destination->id,
            'status' => 'published', 'remote_id' => 'v_pub_file~1', 'remote_url' => 'https://www.tiktok.com/@cafenorte',
        ]);

        $this->tiktokWebhook('post.publish.publicly_available', ['publish_id' => 'v_pub_file~1', 'post_id' => '7291234567890123456'])->assertOk();

        $target->refresh();
        $this->assertSame('7291234567890123456', $target->remote_id);
        $this->assertSame('https://www.tiktok.com/@cafenorte/video/7291234567890123456', $target->remote_url);

        // El mismo aviso otra vez (TikTok reintenta): no se procesa de nuevo.
        $this->tiktokWebhook('post.publish.publicly_available', ['publish_id' => 'v_pub_file~1', 'post_id' => '7291234567890123456'])
            ->assertOk();
    }

    public function test_superadmin_ve_las_urls_que_piden_threads_y_tiktok(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $providers = collect($this->getJson('/api/v1/platform/social-providers')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame(url('/api/v1/data-deletion/threads'), $providers['threads']['setup']['data_deletion_url']);
        $this->assertSame(url('/api/v1/deauthorize/threads'), $providers['threads']['setup']['deauthorize_url']);
        $this->assertSame(url('/api/v1/deauthorize/facebook'), $providers['instagram']['setup']['deauthorize_url']);
        $this->assertSame(url('/api/v1/social/webhooks/tiktok'), $providers['tiktok']['setup']['webhook_url']);
        $this->assertNull($providers['x']['setup']['webhook_url']);
    }
}
