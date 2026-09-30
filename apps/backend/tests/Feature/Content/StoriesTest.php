<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\User;
use App\Modules\Analytics\Services\MetricsSyncService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Providers\FacebookProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Historias (docs/05 y docs/06): el tipo «Historia» se publica como historia en
 * las redes que las admiten (Instagram, Páginas de Facebook), con una sola
 * imagen o video y sin texto.
 */
class StoriesTest extends TestCase
{
    use RefreshDatabase;

    private const CREDENTIALS = ['client_id' => 'APP', 'client_secret' => 'SECRET'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Sleep::fake();
    }

    /**
     * @return array{0: Organization, 1: User, 2: Brand}
     */
    private function brand(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();

        return [$org, $owner, Brand::factory()->create(['organization_id' => $org->id])];
    }

    private function connect(Brand $brand, string $provider, string $externalId): SocialConnectionDestination
    {
        $connection = SocialConnection::query()->create([
            'organization_id' => $brand->organization_id, 'brand_id' => $brand->id, 'provider' => $provider,
            'status' => 'connected', 'external_account_name' => 'Cuenta', 'access_token' => 'USER_TOKEN',
        ]);

        return SocialConnectionDestination::query()->create([
            'organization_id' => $brand->organization_id, 'social_connection_id' => $connection->id,
            'external_id' => $externalId, 'name' => 'Cuenta', 'type' => 'page', 'access_token' => 'PAGE_TOKEN',
        ]);
    }

    private function media(Brand $brand, string $mime = 'image/jpeg'): MediaAsset
    {
        return MediaAsset::query()->create([
            'organization_id' => $brand->organization_id, 'brand_id' => $brand->id, 'disk' => 'local',
            'path' => 'x/historia.' . ($mime === 'video/mp4' ? 'mp4' : 'jpg'), 'original_name' => 'historia',
            'mime_type' => $mime, 'extension' => $mime === 'video/mp4' ? 'mp4' : 'jpg', 'size_bytes' => 1000,
        ]);
    }

    /**
     * @param  array<string, list<MediaAsset>>  $variants  red => archivos
     */
    private function story(Brand $brand, array $variants, string $body = ''): ContentItem
    {
        $content = ContentItem::query()->create([
            'organization_id' => $brand->organization_id, 'brand_id' => $brand->id,
            'title' => 'Historia de otoño', 'type' => 'story', 'status' => 'approved',
        ]);
        foreach ($variants as $provider => $media) {
            $variant = $content->variants()->create([
                'organization_id' => $brand->organization_id, 'provider' => $provider, 'body' => $body, 'format' => 'text',
            ]);
            foreach ($media as $i => $asset) {
                $variant->media()->attach($asset->id, ['position' => $i]);
            }
        }

        return $content;
    }

    public function test_una_historia_exige_una_red_que_las_admita_y_un_solo_archivo(): void
    {
        [$org, $owner, $brand] = $this->brand();
        $this->connect($brand, 'fake', 'dest-fake');
        $this->connect($brand, 'x', 'dest-x');
        $content = $this->story($brand, ['fake' => [], 'x' => [$this->media($brand)]]);

        $errors = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertUnprocessable()
            ->json('errors.variants');

        $all = implode(' | ', $errors);
        $this->assertStringContainsString('lleva una sola imagen o un video', $all);
        $this->assertStringContainsString('no admite historias', $all);
    }

    public function test_se_publica_como_historia_en_la_red(): void
    {
        [$org, $owner, $brand] = $this->brand();
        $this->connect($brand, 'fake', 'dest-fake');
        $content = $this->story($brand, ['fake' => [$this->media($brand)]]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertOk();

        $target = PublicationTarget::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(TargetStatus::PUBLISHED, $target->status);
        $this->assertStringStartsWith('fake-story-', (string) $target->remote_id);
        $this->assertStringContainsString('/stories/', (string) $target->remote_url);
    }

    public function test_en_instagram_se_publica_como_historia_y_sin_texto(): void
    {
        [$org, $owner, $brand] = $this->brand();
        $this->connect($brand, 'instagram', 'IG1');
        // El texto supera el límite de Instagram (2200), pero una historia no lo envía.
        $content = $this->story($brand, ['instagram' => [$this->media($brand)]], str_repeat('a', 3000));
        Http::fake(function (Request $r) {
            return match (true) {
                str_ends_with($r->url(), '/IG1/media') => Http::response(['id' => 'CONT1']),
                str_ends_with($r->url(), '/IG1/media_publish') => Http::response(['id' => 'STORY1']),
                default => Http::response(['permalink' => 'https://www.instagram.com/stories/marca/1/']),
            };
        });

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/content/{$content->public_id}/publish-now")
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media')
            && $r['media_type'] === 'STORIES'
            && isset($r['image_url'])
            && ! isset($r['caption']));
        $target = PublicationTarget::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('STORY1', $target->remote_id);
        $this->assertSame('https://www.instagram.com/stories/marca/1/', $target->remote_url);
    }

    public function test_en_facebook_la_foto_se_sube_sin_publicar_y_se_publica_como_historia(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            return match (true) {
                str_ends_with($r->url(), '/PAGE1/photos') => Http::response(['id' => 'PH1']),
                str_ends_with($r->url(), '/PAGE1/photo_stories') => Http::response(['success' => true, 'post_id' => 55]),
                default => Http::response(['data' => [['post_id' => '55', 'url' => 'https://facebook.com/stories/PAGE1/55']]]),
            };
        });

        $result = (new FacebookProvider())->publish(
            new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'),
            'PAGE1',
            new PublishPayload('texto ignorado', ['https://files.test/h.jpg'], PublishPayload::FORMAT_STORY, mediaTypes: ['image']),
            self::CREDENTIALS,
        );

        $this->assertSame('55', $result->remoteId);
        $this->assertSame('https://facebook.com/stories/PAGE1/55', $result->remoteUrl);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/PAGE1/photos')
            && $r['published'] === 'false' && $r['url'] === 'https://files.test/h.jpg' && ! isset($r['caption']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/PAGE1/photo_stories') && $r['photo_id'] === 'PH1');
    }

    public function test_en_facebook_el_video_pasa_por_video_stories(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            if (str_starts_with($r->url(), 'https://rupload.facebook.com/')) {
                return Http::response(['success' => true]);
            }
            if (str_ends_with($r->url(), '/PAGE1/video_stories')) {
                return $r['upload_phase'] === 'start'
                    ? Http::response(['video_id' => 'V1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/V1'])
                    : Http::response(['success' => true, 'post_id' => 77]);
            }

            return Http::response(['data' => []]);
        });

        $result = (new FacebookProvider())->publish(
            new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'),
            'PAGE1',
            new PublishPayload('', ['https://files.test/h.mp4'], PublishPayload::FORMAT_STORY, mediaTypes: ['video']),
            self::CREDENTIALS,
        );

        $this->assertSame('77', $result->remoteId);
        $this->assertSame('https://www.facebook.com/PAGE1', $result->remoteUrl); // sin enlace directo: la Página
        // La subida lleva el token en la cabecera (no en la URL) y el archivo por URL.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://rupload.facebook.com/video-upload/v25.0/V1'
            && $r->hasHeader('Authorization', 'OAuth PAGE_TOKEN')
            && $r->hasHeader('file_url', 'https://files.test/h.mp4'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/PAGE1/video_stories')
            && $r['upload_phase'] === 'finish' && $r['video_id'] === 'V1');
    }

    public function test_no_envia_el_token_a_una_direccion_de_subida_ajena(): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['video_id' => 'V1', 'upload_url' => 'https://evil.example/upload'])]);

        try {
            (new FacebookProvider())->publish(
                new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'),
                'PAGE1',
                new PublishPayload('', ['https://files.test/h.mp4'], PublishPayload::FORMAT_STORY, mediaTypes: ['video']),
                self::CREDENTIALS,
            );
            $this->fail('Debía rechazar la dirección de subida.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('dirección de subida no válida', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
    }

    public function test_el_reintento_no_duplica_la_historia_de_facebook(): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        $result = (new FacebookProvider())->publish(
            new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'),
            'PAGE1',
            new PublishPayload(
                '',
                ['https://files.test/h.jpg'],
                PublishPayload::FORMAT_STORY,
                mediaTypes: ['image'],
                checkpoint: new PublishCheckpoint(['facebook.story_post' => '55']),
            ),
            self::CREDENTIALS,
        );

        $this->assertSame('55', $result->remoteId);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_las_metricas_de_las_historias_no_se_guardan(): void
    {
        [, , $brand] = $this->brand();
        $destination = $this->connect($brand, 'fake', 'dest-fake');
        $content = $this->story($brand, ['fake' => [$this->media($brand)]]);
        $target = PublicationTarget::query()->create([
            'organization_id' => $brand->organization_id, 'post_variant_id' => $content->variants()->firstOrFail()->id,
            'social_connection_destination_id' => $destination->id, 'status' => TargetStatus::PUBLISHED->value,
            'remote_id' => 'fake-story-1', 'published_at' => now()->subHour(),
        ]);

        $this->assertNull(app(MetricsSyncService::class)->syncPost($target));
        $this->assertDatabaseCount('post_metric_snapshots', 0);
    }
}
