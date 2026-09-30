<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\PublishingService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Las redes nuevas en el flujo completo: catálogo y ajustes de SUPERADMIN,
 * opciones obligatorias al programar, opciones en vivo para el editor y
 * renovación del token justo antes de publicar.
 */
class NewProvidersIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Http::preventStrayRequests();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Brand, 3: SocialConnection, 4: SocialConnectionDestination}
     */
    private function connected(string $provider, array $connection = []): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $record = SocialProvider::query()->where('key', $provider)->firstOrFail();
        $record->is_enabled = true;
        $record->credentials = ['client_id' => 'CID', 'client_secret' => 'SECRET'];
        $record->save();

        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $model = SocialConnection::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'provider' => $provider,
            'status' => 'connected',
            'external_account_name' => 'Cuenta',
            'access_token' => 'ACCESS',
            ...$connection,
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id,
            'social_connection_id' => $model->id,
            'external_id' => 'U1',
            'name' => '@cuenta',
            'type' => 'profile',
        ]);

        return [$owner, $org, $brand, $model, $destination];
    }

    private function content(Organization $org, Brand $brand, string $provider, array $options = [], string $body = 'Hola'): ContentItem
    {
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Pieza', 'status' => 'approved',
        ]);
        $content->variants()->create([
            'organization_id' => $org->id, 'provider' => $provider, 'body' => $body, 'format' => 'text', 'options' => $options ?: null,
        ]);

        return $content;
    }

    public function test_catalogo_y_ajustes_de_superadmin_para_las_redes_nuevas(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $providers = collect($this->getJson('/api/v1/platform/social-providers')->assertOk()->json('data'))->keyBy('key');
        foreach (['linkedin', 'threads', 'tiktok', 'x', 'youtube'] as $key) {
            $this->assertFalse($providers[$key]['is_enabled'], "{$key} debe llegar deshabilitado");
        }
        $this->assertSame('Client key', $providers['tiktok']['credential_labels']['client_id']);
        $this->assertSame('202609', $providers['linkedin']['api_version']['default']);
        $this->assertSame('v1.0', $providers['threads']['api_version']['default']);
        $this->assertNull($providers['x']['api_version']);
        $this->assertStringContainsString('Login Kit', $providers['tiktok']['setup']['redirect_hint']);
        // Permisos opcionales que activan funciones (p. ej. borrar en Threads), sin pedirlos por defecto.
        $this->assertContains('threads_delete', array_column($providers['threads']['optional_scopes'], 'scope'));
        $this->assertNotContains('threads_delete', $providers['threads']['default_scopes']);
        $this->assertContains('w_organization_social', array_column($providers['linkedin']['optional_scopes'], 'scope'));
        $this->assertSame([], $providers['x']['optional_scopes']);

        $this->putJson('/api/v1/platform/social-providers/linkedin', ['api_version' => '202607'])
            ->assertOk()
            ->assertJsonPath('data.api_version.value', '202607');
        $this->putJson('/api/v1/platform/social-providers/linkedin', ['api_version' => '2026-07'])->assertStatus(422);
        $this->putJson('/api/v1/platform/social-providers/x', ['api_version' => 'v2'])->assertStatus(422);

        // Los scopes de Google tienen forma de URL.
        $this->putJson('/api/v1/platform/social-providers/youtube', ['scopes' => [
            'https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly',
        ]])->assertOk()->assertJsonCount(2, 'data.scopes');
    }

    public function test_programar_en_tiktok_exige_video_y_las_opciones_obligatorias(): void
    {
        [$owner, $org, $brand] = $this->connected('tiktok');
        $content = $this->content($org, $brand, 'tiktok');

        $response = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertStatus(422);

        $errors = implode(' ', $response->json('errors.variants'));
        $this->assertStringContainsString('TikTok exige un video', $errors);
        $this->assertStringContainsString('Elige quién puede ver la publicación en TikTok', $errors);
        $this->assertStringContainsString('confirmación de uso de música', $errors);
    }

    public function test_texto_de_x_se_mide_con_su_longitud_ponderada(): void
    {
        [$owner, $org, $brand] = $this->connected('x');
        // 270 letras + una URL (cuenta 23) = 293 > 280.
        $content = $this->content($org, $brand, 'x', body: str_repeat('a', 270) . ' https://ejemplo.com/x');

        $response = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertStatus(422);

        $this->assertStringContainsString('280 caracteres', implode(' ', $response->json('errors.variants')));
    }

    public function test_el_editor_consulta_en_vivo_las_opciones_de_cada_cuenta(): void
    {
        [$owner, $org, $brand] = $this->connected('tiktok');
        $content = $this->content($org, $brand, 'tiktok');
        Http::fake(['open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['data' => [
            'creator_nickname' => 'Café Norte', 'creator_username' => 'cafenorte', 'privacy_level_options' => ['SELF_ONLY'],
            'comment_disabled' => false, 'duet_disabled' => false, 'stitch_disabled' => false, 'max_video_post_duration_sec' => 300,
        ], 'error' => ['code' => 'ok']])]);
        $variant = $content->variants()->firstOrFail();

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/variants/{$variant->public_id}/publish-options")
            ->assertOk()
            ->assertJsonPath('data.accounts.0.name', '@cuenta')
            ->assertJsonPath('data.accounts.0.options.creator_nickname', 'Café Norte')
            ->assertJsonPath('data.accounts.0.options.privacy_level_options', ['SELF_ONLY']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ACCESS'));

        // Las opciones se eligen mientras el contenido es editable (antes de aprobarlo)
        // y dejan de bloquear la programación (salvo el video, que sigue faltando).
        $content->update(['status' => 'draft']);
        $this->actingInOrganization($owner, $org)
            ->patchJson("/api/v1/variants/{$variant->public_id}", ['options' => ['privacy_level' => 'SELF_ONLY', 'consent' => true]])
            ->assertOk()
            ->assertJsonPath('data.options.privacy_level', 'SELF_ONLY');
        $content->update(['status' => 'approved']);
        $response = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertStatus(422);
        $this->assertSame(['TikTok exige un video.'], $response->json('errors.variants'));
    }

    public function test_renueva_el_token_de_x_justo_antes_de_publicar(): void
    {
        [, $org, $brand, $connection, $destination] = $this->connected('x', [
            'refresh_token' => 'RT1',
            'token_expires_at' => now()->addMinutes(3),
        ]);
        $content = $this->content($org, $brand, 'x');
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $content->variants()->firstOrFail()->id,
            'social_connection_destination_id' => $destination->id,
            'status' => 'scheduled',
        ]);
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 7200]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '777']], 201),
        ]);

        app(PublishingService::class)->publishTarget($target);

        $this->assertSame('published', $target->fresh()->status->value);
        $this->assertSame('777', $target->fresh()->remote_id);
        $connection->refresh();
        $this->assertSame('AT2', $connection->access_token);
        $this->assertSame('RT2', $connection->refresh_token); // refresh token rotado guardado
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/2/tweets') && $r->hasHeader('Authorization', 'Bearer AT2'));
    }

    public function test_si_no_puede_renovar_la_conexion_caduca_y_no_se_publica(): void
    {
        [, $org, $brand, $connection, $destination] = $this->connected('x', [
            'refresh_token' => 'RT_REVOCADO',
            'token_expires_at' => now()->subMinute(),
        ]);
        $content = $this->content($org, $brand, 'x');
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id,
            'post_variant_id' => $content->variants()->firstOrFail()->id,
            'social_connection_destination_id' => $destination->id,
            'status' => 'scheduled',
        ]);
        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_request', 'error_description' => 'Value passed for the token was invalid.'], 400)]);

        app(PublishingService::class)->publishTarget($target);

        $this->assertSame('failed', $target->fresh()->status->value);
        $this->assertSame('expired', $connection->fresh()->status->value);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/2/tweets'));
    }
}
