<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\FacebookProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica el adaptador real de Meta (Páginas) contra respuestas simuladas de
 * Graph API (Http::fake). No hace llamadas de red reales.
 */
class FacebookProviderTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'APP', 'client_secret' => 'SECRET'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // ninguna petición sin simular sale a la red
    }

    /** Tokens con page token guardado en el destino (caso normal tras OAuth). */
    private function pageTokens(): OAuthTokens
    {
        return new OAuthTokens('USER_TOKEN', destinationToken: 'PAGE_TOKEN');
    }

    private function provider(): FacebookProvider
    {
        return new FacebookProvider();
    }

    public function test_usa_la_version_de_graph_configurada(): void
    {
        config(['services.meta.graph_version' => 'v25.0']);
        Http::fake(['*' => Http::response(['id' => 'PAGE1_POST1'])]);

        $this->provider()->publish($this->pageTokens(), 'PAGE1', new PublishPayload('Hola'), self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v25.0/PAGE1/feed'));

        // El ajuste del proveedor (SUPERADMIN) tiene prioridad sobre la configuración.
        $this->provider()->publish($this->pageTokens(), 'PAGE1', new PublishPayload('Hola'), [
            ...self::CREDENTIALS, 'graph_version' => 'v26.0',
        ]);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v26.0/PAGE1/feed'));
    }

    public function test_canjea_el_codigo_por_un_token_de_larga_duracion(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'fb_exchange_token')) {
                return Http::response(['access_token' => 'LONG_TOKEN', 'expires_in' => 5184000]);
            }

            return Http::response(['access_token' => 'SHORT_TOKEN', 'expires_in' => 3600]);
        });

        $tokens = $this->provider()->exchangeCode('CODE', 'https://app.test/cb', null, self::CREDENTIALS);

        $this->assertSame('LONG_TOKEN', $tokens->accessToken);
        $this->assertNull($tokens->expiresAt); // los page tokens derivados no caducan
    }

    public function test_destinos_incluyen_el_page_token(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => 'PAGE1', 'name' => 'Mi Página', 'category' => 'Tienda', 'access_token' => 'PAGE_TOKEN_1'],
        ]])]);

        $destinations = $this->provider()->fetchDestinations(new OAuthTokens('USER_TOKEN'), self::CREDENTIALS);

        $this->assertCount(1, $destinations);
        $this->assertSame('PAGE1', $destinations[0]->externalId);
        $this->assertSame('PAGE_TOKEN_1', $destinations[0]->accessToken);
    }

    public function test_publica_texto_con_el_page_token_del_destino(): void
    {
        Http::fake(['*/PAGE1/feed' => Http::response(['id' => 'PAGE1_POST1'])]);

        $result = $this->provider()->publish($this->pageTokens(), 'PAGE1', new PublishPayload('Hola mundo'), self::CREDENTIALS);

        $this->assertSame('PAGE1_POST1', $result->remoteId);
        $this->assertStringContainsString('PAGE1_POST1', (string) $result->remoteUrl);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/feed')
            && $r['message'] === 'Hola mundo'
            && $r['access_token'] === 'PAGE_TOKEN');
        // Con page token guardado no se deriva otro.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fields=access_token'));
    }

    public function test_texto_con_enlace_se_publica_como_enlace(): void
    {
        Http::fake(['*' => Http::response(['id' => 'PAGE1_POST2'])]);

        $this->provider()->publish($this->pageTokens(), 'PAGE1', new PublishPayload('Mira esto https://loop7.test/blog'), self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/feed') && $r['link'] === 'https://loop7.test/blog');
    }

    public function test_sin_page_token_lo_deriva_del_token_de_usuario(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'fields=access_token')) {
                return Http::response(['access_token' => 'DERIVED_TOKEN']);
            }

            return Http::response(['id' => 'PAGE1_POST1']);
        });

        $this->provider()->publish(new OAuthTokens('USER_TOKEN'), 'PAGE1', new PublishPayload('Hola'), self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/feed') && $r['access_token'] === 'DERIVED_TOKEN');
    }

    public function test_publica_una_imagen(): void
    {
        Http::fake(['*/PAGE1/photos' => Http::response(['id' => 'PHOTO1', 'post_id' => 'PAGE1_POST9'])]);

        $payload = new PublishPayload('Con foto', ['https://cdn.test/img.jpg']);
        $result = $this->provider()->publish($this->pageTokens(), 'PAGE1', $payload, self::CREDENTIALS);

        $this->assertSame('PAGE1_POST9', $result->remoteId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/photos')
            && $r['url'] === 'https://cdn.test/img.jpg' && $r['caption'] === 'Con foto');
    }

    public function test_publica_varias_imagenes_en_una_sola_publicacion(): void
    {
        $photo = 0;
        Http::fake(function (Request $r) use (&$photo) {
            if (str_contains($r->url(), '/photos')) {
                $photo++;

                return Http::response(['id' => 'PH' . $photo]);
            }

            return Http::response(['id' => 'PAGE1_MULTI']);
        });

        $payload = new PublishPayload('Galería', ['https://cdn.test/1.jpg', 'https://cdn.test/2.jpg']);
        $result = $this->provider()->publish($this->pageTokens(), 'PAGE1', $payload, self::CREDENTIALS);

        $this->assertSame('PAGE1_MULTI', $result->remoteId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/photos') && $r['published'] === 'false');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/feed')
            && $r['attached_media[0]'] === '{"media_fbid":"PH1"}'
            && $r['attached_media[1]'] === '{"media_fbid":"PH2"}');
    }

    public function test_publica_video(): void
    {
        Http::fake(['*/PAGE1/videos' => Http::response(['id' => 'VIDEO1'])]);

        $payload = new PublishPayload('Mira el video', ['https://cdn.test/v.mp4'], mediaTypes: ['video']);
        $result = $this->provider()->publish($this->pageTokens(), 'PAGE1', $payload, self::CREDENTIALS);

        $this->assertSame('VIDEO1', $result->remoteId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/videos')
            && $r['file_url'] === 'https://cdn.test/v.mp4' && $r['description'] === 'Mira el video');
    }

    public function test_metricas_de_cuenta_con_metricas_vigentes(): void
    {
        Http::fake(function (Request $r) {
            $u = $r->url();
            if (str_contains($u, 'fields=followers_count')) {
                return Http::response(['followers_count' => 1200, 'fan_count' => 1000]);
            }
            if (str_contains($u, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'page_media_view', 'values' => [['value' => 450], ['value' => 500]]],
                    ['name' => 'page_total_media_view_unique', 'values' => [['value' => 400]]],
                    ['name' => 'page_post_engagements', 'values' => [['value' => 90]]],
                ]]);
            }

            return Http::response([], 404);
        });

        $m = $this->provider()->fetchAccountMetrics($this->pageTokens(), 'PAGE1', self::CREDENTIALS);

        $this->assertSame(1200, $m->followers);
        $this->assertSame(400, $m->reach);
        $this->assertSame(500, $m->impressions); // último valor de la serie
        $this->assertSame(90, $m->engagement);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'page_media_view'));
    }

    public function test_metrica_no_disponible_no_anula_las_demas(): void
    {
        Http::fake(function (Request $r) {
            $u = $r->url();
            if (str_contains($u, 'fields=followers_count')) {
                return Http::response(['followers_count' => 10]);
            }
            if (str_contains($u, '/insights') && str_contains($u, 'metric=page_post_engagements')) {
                return Http::response(['data' => [['name' => 'page_post_engagements', 'values' => [['value' => 7]]]]]);
            }
            if (str_contains($u, '/insights') && str_contains($u, 'metric=page_media_view&')) {
                return Http::response(['data' => [['name' => 'page_media_view', 'values' => [['value' => 30]]]]]);
            }

            // Petición combinada o métrica retirada: error de Meta.
            return Http::response(['error' => ['message' => 'Invalid metric', 'code' => 100]], 400);
        });

        $m = $this->provider()->fetchAccountMetrics($this->pageTokens(), 'PAGE1', self::CREDENTIALS);

        $this->assertSame(10, $m->followers);
        $this->assertSame(30, $m->impressions);
        $this->assertSame(0, $m->reach);
        $this->assertSame(7, $m->engagement);
    }

    public function test_metricas_de_post(): void
    {
        Http::fake(function (Request $r) {
            $u = $r->url();
            if (str_contains($u, 'fields=reactions')) {
                return Http::response([
                    'reactions' => ['summary' => ['total_count' => 10]],
                    'comments' => ['summary' => ['total_count' => 3]],
                    'shares' => ['count' => 2],
                ]);
            }
            if (str_contains($u, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'post_media_view', 'values' => [['value' => 800]]],
                    ['name' => 'post_total_media_view_unique', 'values' => [['value' => 600]]],
                    ['name' => 'post_clicks', 'values' => [['value' => 40]]],
                ]]);
            }

            return Http::response([], 404);
        });

        $m = $this->provider()->fetchPostMetrics($this->pageTokens(), 'PAGE1_POST1', self::CREDENTIALS);

        $this->assertSame(800, $m->impressions);
        $this->assertSame(600, $m->reach);
        $this->assertSame(10, $m->likes);
        $this->assertSame(3, $m->comments);
        $this->assertSame(2, $m->shares);
        $this->assertSame(40, $m->clicks);
    }

    public function test_lee_comentarios_y_omite_los_de_la_propia_pagina(): void
    {
        Http::fake(['*/PAGE1/feed*' => Http::response(['data' => [[
            'id' => 'PAGE1_POST1',
            'comments' => ['data' => [
                [
                    'id' => 'POST1_C1',
                    'message' => '¿Tienen envío?',
                    'created_time' => '2026-09-20T10:00:00+0000',
                    'from' => ['id' => 'u1', 'name' => 'Ana'],
                ],
                [
                    'id' => 'POST1_C2',
                    'message' => 'Sí, a todo el país.',
                    'created_time' => '2026-09-20T11:00:00+0000',
                    'from' => ['id' => 'PAGE1', 'name' => 'Mi Página'],
                ],
            ]],
        ]]])]);

        $threads = $this->provider()->fetchConversations($this->pageTokens(), 'PAGE1', self::CREDENTIALS);

        $this->assertCount(1, $threads);
        $this->assertSame('POST1_C1', $threads[0]->externalId);
        $this->assertSame('Ana', $threads[0]->participantName);
        $this->assertSame('¿Tienen envío?', $threads[0]->messages[0]->body);
    }

    public function test_responde_a_un_comentario_con_el_token_del_destino(): void
    {
        Http::fake(['*/POST1_C1/comments' => Http::response(['id' => 'C1_REPLY'])]);

        $result = $this->provider()->replyToConversation($this->pageTokens(), 'POST1_C1', 'Sí, con gusto.', self::CREDENTIALS);

        $this->assertSame('C1_REPLY', $result->externalId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/POST1_C1/comments')
            && $r['message'] === 'Sí, con gusto.'
            && $r['access_token'] === 'PAGE_TOKEN');
    }

    public function test_error_de_meta_lanza_excepcion_legible(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Permiso denegado', 'code' => 200]], 403)]);

        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('Permiso denegado');
        $this->provider()->publish($this->pageTokens(), 'PAGE1', new PublishPayload('x'), self::CREDENTIALS);
    }

    public function test_token_caducado_lanza_excepcion_de_token(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400)]);

        $this->expectException(SocialTokenExpiredException::class);
        $this->provider()->publish($this->pageTokens(), 'PAGE1', new PublishPayload('x'), self::CREDENTIALS);
    }

    public function test_verifica_credenciales_validas_de_la_app(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'APP|TOKEN'])]);

        $this->provider()->verifyCredentials(self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'grant_type=client_credentials'));
    }

    public function test_credenciales_invalidas_de_la_app_lanzan_excepcion(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid client_secret', 'code' => 1]], 400)]);

        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('Invalid client_secret');
        $this->provider()->verifyCredentials(self::CREDENTIALS);
    }
}
