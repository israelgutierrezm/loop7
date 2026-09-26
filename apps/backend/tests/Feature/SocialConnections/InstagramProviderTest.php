<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Providers\InstagramProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Adaptador de Instagram (Facebook Login) contra Graph API simulada: publicación
 * en dos pasos (contenedor → media_publish), métricas y comentarios.
 */
class InstagramProviderTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'APP', 'client_secret' => 'SECRET'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(); // la espera del procesamiento de video no duerme en las pruebas
    }

    private function tokens(): OAuthTokens
    {
        return new OAuthTokens('USER_TOKEN', destinationToken: 'PAGE_TOKEN');
    }

    private function provider(): InstagramProvider
    {
        return new InstagramProvider();
    }

    public function test_destinos_son_las_cuentas_profesionales_vinculadas(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            [
                'id' => 'PAGE1', 'name' => 'Mi Página', 'access_token' => 'PAGE_TOKEN_1',
                'instagram_business_account' => ['id' => 'IG1', 'username' => 'mimarca'],
            ],
            ['id' => 'PAGE2', 'name' => 'Página sin Instagram', 'access_token' => 'PAGE_TOKEN_2'],
        ]])]);

        $destinations = $this->provider()->fetchDestinations(new OAuthTokens('USER_TOKEN'), self::CREDENTIALS);

        $this->assertCount(1, $destinations);
        $this->assertSame('IG1', $destinations[0]->externalId);
        $this->assertSame('@mimarca', $destinations[0]->name);
        $this->assertSame('instagram_business', $destinations[0]->type);
        $this->assertSame('PAGE_TOKEN_1', $destinations[0]->accessToken);
        $this->assertSame('PAGE1', $destinations[0]->metadata['page_id']);
    }

    public function test_publica_una_imagen_en_dos_pasos(): void
    {
        Http::fake([
            '*/IG1/media_publish' => Http::response(['id' => 'MEDIA1']),
            '*/IG1/media' => Http::response(['id' => 'CONTAINER1']),
            '*/MEDIA1?*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        ]);

        $payload = new PublishPayload('Nueva colección', ['https://cdn.test/1.jpg']);
        $result = $this->provider()->publish($this->tokens(), 'IG1', $payload, self::CREDENTIALS);

        $this->assertSame('MEDIA1', $result->remoteId);
        $this->assertSame('https://www.instagram.com/p/abc/', $result->remoteUrl);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media')
            && $r['image_url'] === 'https://cdn.test/1.jpg'
            && $r['caption'] === 'Nueva colección'
            && $r['access_token'] === 'PAGE_TOKEN');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media_publish') && $r['creation_id'] === 'CONTAINER1');
    }

    public function test_publica_un_reel_esperando_el_procesamiento(): void
    {
        $checks = 0;
        Http::fake(function (Request $r) use (&$checks) {
            $u = $r->url();
            if (str_ends_with($u, '/IG1/media')) {
                return Http::response(['id' => 'REEL_CONTAINER']);
            }
            if (str_contains($u, '/REEL_CONTAINER?') && str_contains($u, 'status_code')) {
                $checks++;

                return Http::response(['status_code' => $checks < 2 ? 'IN_PROGRESS' : 'FINISHED']);
            }
            if (str_ends_with($u, '/IG1/media_publish')) {
                return Http::response(['id' => 'MEDIA_REEL']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/reel/xyz/']);
        });

        $payload = new PublishPayload('Tutorial', ['https://cdn.test/v.mp4'], mediaTypes: ['video']);
        $result = $this->provider()->publish($this->tokens(), 'IG1', $payload, self::CREDENTIALS);

        $this->assertSame('MEDIA_REEL', $result->remoteId);
        $this->assertSame(2, $checks);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media')
            && $r['media_type'] === 'REELS' && $r['video_url'] === 'https://cdn.test/v.mp4');
        Sleep::assertSleptTimes(1);
    }

    public function test_publica_un_carrusel(): void
    {
        $container = 0;
        Http::fake(function (Request $r) use (&$container) {
            $u = $r->url();
            if (str_ends_with($u, '/IG1/media')) {
                $container++;

                return Http::response(['id' => 'C' . $container]);
            }
            if (str_contains($u, 'status_code')) {
                return Http::response(['status_code' => 'FINISHED']);
            }
            if (str_ends_with($u, '/IG1/media_publish')) {
                return Http::response(['id' => 'MEDIA_CAROUSEL']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/p/car/']);
        });

        $payload = new PublishPayload('Tres fotos', [
            'https://cdn.test/1.jpg', 'https://cdn.test/2.jpg', 'https://cdn.test/3.jpg',
        ]);
        $result = $this->provider()->publish($this->tokens(), 'IG1', $payload, self::CREDENTIALS);

        $this->assertSame('MEDIA_CAROUSEL', $result->remoteId);
        Http::assertSentCount(7); // 3 hijos + carrusel + estado + media_publish + permalink
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media')
            && ($r->data()['is_carousel_item'] ?? null) === 'true');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media')
            && ($r->data()['media_type'] ?? null) === 'CAROUSEL'
            && ($r->data()['children'] ?? null) === 'C1,C2,C3'
            && ($r->data()['caption'] ?? null) === 'Tres fotos');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media_publish') && $r['creation_id'] === 'C4');
    }

    public function test_carrusel_con_videos_se_procesa_en_paralelo(): void
    {
        $sequence = [];
        $container = 0;
        Http::fake(function (Request $r) use (&$sequence, &$container) {
            $u = $r->url();
            if (str_ends_with($u, '/IG1/media')) {
                $container++;
                $sequence[] = 'crear';

                return Http::response(['id' => 'C' . $container]);
            }
            if (str_contains($u, 'status_code')) {
                $sequence[] = 'estado';

                return Http::response(['status_code' => 'FINISHED']);
            }
            if (str_ends_with($u, '/IG1/media_publish')) {
                return Http::response(['id' => 'MEDIA_MIX']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/p/mix/']);
        });

        $payload = new PublishPayload(
            'Mixto',
            ['https://cdn.test/1.mp4', 'https://cdn.test/2.jpg', 'https://cdn.test/3.mp4'],
            mediaTypes: ['video', 'image', 'video'],
        );
        $this->provider()->publish($this->tokens(), 'IG1', $payload, self::CREDENTIALS);

        // Los tres hijos se crean antes de consultar el estado de ningún video.
        $this->assertSame(['crear', 'crear', 'crear', 'estado', 'estado', 'crear', 'estado'], $sequence);
    }

    public function test_video_que_no_termina_agota_la_espera_y_se_reintentara(): void
    {
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/IG1/media')) {
                return Http::response(['id' => 'SLOW']);
            }

            return Http::response(['status_code' => 'IN_PROGRESS']);
        });

        try {
            $payload = new PublishPayload('x', ['https://cdn.test/v.mp4'], mediaTypes: ['video']);
            $this->provider()->publish($this->tokens(), 'IG1', $payload, self::CREDENTIALS);
            $this->fail('Se esperaba SocialProviderException.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('sigue procesando', $e->getMessage());
        }

        Http::assertSentCount(1 + InstagramProvider::STATUS_CHECKS);
        Sleep::assertSleptTimes(InstagramProvider::STATUS_CHECKS - 1);
    }

    public function test_un_reintento_retoma_el_video_que_meta_estaba_procesando(): void
    {
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
                return Http::response(['id' => 'MEDIA_REEL']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/reel/r/']);
        });
        $checkpoint = new PublishCheckpoint();
        $payload = fn () => new PublishPayload('Tutorial', ['https://cdn.test/v.mp4?firma=' . uniqid()], mediaTypes: ['video'], checkpoint: $checkpoint);

        // 1er intento: Meta no termina a tiempo.
        try {
            $this->provider()->publish($this->tokens(), 'IG1', $payload(), self::CREDENTIALS);
            $this->fail('Se esperaba que siguiera procesando.');
        } catch (SocialProviderException) {
        }

        // 2º intento (otra URL firmada): retoma REEL1 sin volver a subir el video.
        $ready = true;
        $result = $this->provider()->publish($this->tokens(), 'IG1', $payload(), self::CREDENTIALS);

        $this->assertSame('MEDIA_REEL', $result->remoteId);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/IG1/media')));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media_publish') && $r['creation_id'] === 'REEL1');
        $this->assertSame('MEDIA_REEL', $checkpoint->get('instagram.media_id'));
    }

    public function test_si_ya_se_publico_no_se_publica_otra_vez(): void
    {
        Http::fake(['*' => Http::response(['permalink' => 'https://www.instagram.com/p/ya/'])]);
        $checkpoint = new PublishCheckpoint(['instagram.media_id' => 'MEDIA_YA']);

        $result = $this->provider()->publish($this->tokens(), 'IG1', new PublishPayload('x', ['https://cdn.test/1.jpg'], checkpoint: $checkpoint), self::CREDENTIALS);

        $this->assertSame('MEDIA_YA', $result->remoteId);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/IG1/media'));
    }

    public function test_un_contenedor_fallido_o_de_otro_contenido_se_vuelve_a_crear(): void
    {
        $created = 0;
        Http::fake(function (Request $r) use (&$created) {
            $u = $r->url();
            if (str_ends_with($u, '/IG1/media')) {
                $created++;

                return Http::response(['id' => 'NUEVO' . $created]);
            }
            if (str_contains($u, '/VIEJO?') && str_contains($u, 'status_code')) {
                return Http::response(['status_code' => 'ERROR', 'status' => 'Formato no admitido']);
            }
            if (str_ends_with($u, '/IG1/media_publish')) {
                return Http::response(['id' => 'MEDIA_OK']);
            }

            return Http::response(['status_code' => 'FINISHED', 'permalink' => 'https://www.instagram.com/p/ok/']);
        });

        // Contenedor guardado que Meta dio por fallido: se crea otro.
        $signature = sha1((string) json_encode(['caption' => 'Hola']));
        $checkpoint = new PublishCheckpoint(['instagram.containers' => [
            'main' => ['id' => 'VIEJO', 'signature' => $signature, 'created_at' => now()->getTimestamp()],
        ]]);
        $this->provider()->publish($this->tokens(), 'IG1', new PublishPayload('Hola', ['https://cdn.test/1.jpg'], checkpoint: $checkpoint), self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media_publish') && $r['creation_id'] === 'NUEVO1');

        // Contenedor de un texto distinto (se editó el post): tampoco se reutiliza.
        $checkpoint = new PublishCheckpoint(['instagram.containers' => [
            'main' => ['id' => 'OTRO', 'signature' => sha1('otro'), 'created_at' => now()->getTimestamp()],
        ]]);
        $this->provider()->publish($this->tokens(), 'IG1', new PublishPayload('Hola', ['https://cdn.test/1.jpg'], checkpoint: $checkpoint), self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG1/media_publish') && $r['creation_id'] === 'NUEVO2');
    }

    public function test_sin_imagen_ni_video_no_publica(): void
    {
        Http::fake();

        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('Instagram requiere al menos una imagen o un video');
        $this->provider()->publish($this->tokens(), 'IG1', new PublishPayload('Sólo texto'), self::CREDENTIALS);
    }

    public function test_video_con_error_de_procesamiento(): void
    {
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/IG1/media')) {
                return Http::response(['id' => 'BAD']);
            }

            return Http::response(['status_code' => 'ERROR', 'status' => 'Formato no admitido']);
        });

        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('Formato no admitido');
        $payload = new PublishPayload('x', ['https://cdn.test/v.avi'], mediaTypes: ['video']);
        $this->provider()->publish($this->tokens(), 'IG1', $payload, self::CREDENTIALS);
    }

    public function test_metricas_de_cuenta(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'total_value' => ['value' => 900]],
                    ['name' => 'views', 'total_value' => ['value' => 2500]],
                    ['name' => 'total_interactions', 'total_value' => ['value' => 120]],
                ]]);
            }

            return Http::response(['followers_count' => 3400, 'media_count' => 58]);
        });

        $m = $this->provider()->fetchAccountMetrics($this->tokens(), 'IG1', self::CREDENTIALS);

        $this->assertSame(3400, $m->followers);
        $this->assertSame(900, $m->reach);
        $this->assertSame(2500, $m->impressions);
        $this->assertSame(120, $m->engagement);
        $this->assertSame(58, $m->postsCount);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'metric_type=total_value'));
    }

    public function test_metricas_de_publicacion(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'views', 'values' => [['value' => 1500]]],
                    ['name' => 'reach', 'values' => [['value' => 1100]]],
                    ['name' => 'shares', 'values' => [['value' => 12]]],
                ]]);
            }

            return Http::response(['like_count' => 210, 'comments_count' => 14]);
        });

        $m = $this->provider()->fetchPostMetrics($this->tokens(), 'MEDIA1', self::CREDENTIALS);

        $this->assertSame(1500, $m->impressions);
        $this->assertSame(1100, $m->reach);
        $this->assertSame(210, $m->likes);
        $this->assertSame(14, $m->comments);
        $this->assertSame(12, $m->shares);
    }

    public function test_lee_comentarios_y_responde(): void
    {
        Http::fake([
            '*/IG1/media?*' => Http::response(['data' => [[
                'id' => 'MEDIA1',
                'comments' => ['data' => [
                    ['id' => 'COMMENT1', 'text' => '¿Precio?', 'timestamp' => '2026-09-21T09:00:00+0000', 'username' => 'cliente1', 'from' => ['id' => 'IGU1', 'username' => 'cliente1']],
                    ['id' => 'COMMENT2', 'text' => 'Te escribimos por DM', 'timestamp' => '2026-09-21T09:05:00+0000', 'username' => 'mimarca', 'from' => ['id' => 'IG1', 'username' => 'mimarca']],
                ]],
            ]]]),
            '*/COMMENT1/replies' => Http::response(['id' => 'REPLY1']),
        ]);

        $threads = $this->provider()->fetchConversations($this->tokens(), 'IG1', self::CREDENTIALS);

        $this->assertCount(1, $threads); // la respuesta de la propia cuenta se omite
        $this->assertSame('COMMENT1', $threads[0]->externalId);
        $this->assertSame('@cliente1', $threads[0]->participantName);
        $this->assertSame('¿Precio?', $threads[0]->messages[0]->body);

        $reply = $this->provider()->replyToConversation($this->tokens(), 'COMMENT1', '¡Hola! Te enviamos info.', self::CREDENTIALS);

        $this->assertSame('REPLY1', $reply->externalId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/COMMENT1/replies')
            && $r['message'] === '¡Hola! Te enviamos info.' && $r['access_token'] === 'PAGE_TOKEN');
    }
}
