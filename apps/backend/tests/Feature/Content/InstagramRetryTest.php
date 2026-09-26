<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\PublishingService;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * El progreso de Instagram se guarda en el target entre intentos del job: el
 * reintento retoma el contenedor que Meta ya procesaba y, al publicar, se limpia.
 */
class InstagramRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_reintento_del_job_retoma_el_contenedor_guardado(): void
    {
        $this->seedRbac();
        Sleep::fake();
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'instagram',
            'status' => 'connected', 'external_account_name' => '@marca', 'access_token' => 'USER_TOKEN',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'IG1', 'name' => '@marca', 'type' => 'instagram_business', 'access_token' => 'PAGE_TOKEN',
        ]);
        $video = MediaAsset::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'disk' => 'local', 'path' => 'x/reel.mp4',
            'original_name' => 'reel.mp4', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'size_bytes' => 1000,
        ]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Reel', 'status' => 'publishing',
        ]);
        $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => 'instagram', 'body' => 'Mira esto', 'format' => 'video']);
        $variant->media()->attach($video->id, ['position' => 0]);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id, 'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id, 'status' => 'pending',
        ]);

        $ready = false;
        Http::fake(function (Request $r) use (&$ready) {
            $u = $r->url();
            if (str_ends_with($u, '/IG1/media')) {
                return Http::response(['id' => 'REEL1']);
            }
            if (str_contains($u, 'status_code')) {
                return Http::response(['status_code' => $ready ? 'FINISHED' : 'IN_PROGRESS']);
            }
            if (str_ends_with($u, '/IG1/media_publish')) {
                return Http::response(['id' => 'MEDIA1']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/reel/x/']);
        });
        $publishing = app(PublishingService::class);

        // Intento 1: Meta sigue procesando; el contenedor queda guardado en el target.
        try {
            $publishing->publishTarget($target->fresh(), finalAttempt: false);
            $this->fail('Se esperaba que el job reintentara.');
        } catch (\Throwable) {
        }
        $this->assertSame('REEL1', $target->fresh()->provider_state['data']['instagram.containers']['main']['id']);

        // Intento 2: lo retoma (un solo contenedor creado en total) y publica.
        $ready = true;
        $publishing->publishTarget($target->fresh(), finalAttempt: false);

        $target->refresh();
        $this->assertSame('published', $target->status->value);
        $this->assertSame('MEDIA1', $target->remote_id);
        $this->assertNull($target->provider_state);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/IG1/media')));
    }
}
