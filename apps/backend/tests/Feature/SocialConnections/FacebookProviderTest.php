<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Providers\FacebookProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica el adaptador real de Meta contra respuestas simuladas de Graph API
 * (Http::fake). No hace llamadas de red reales.
 */
class FacebookProviderTest extends TestCase
{
    private function tokens(): OAuthTokens
    {
        return new OAuthTokens('USER_TOKEN', null, null, []);
    }

    private function provider(): FacebookProvider
    {
        return new FacebookProvider();
    }

    public function test_publica_texto_en_pagina_con_page_token(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'fields=access_token')) {
                return Http::response(['access_token' => 'PAGE_TOKEN']);
            }
            if (str_contains($r->url(), '/feed')) {
                return Http::response(['id' => 'PAGE1_POST1']);
            }

            return Http::response([], 404);
        });

        $result = $this->provider()->publish($this->tokens(), 'PAGE1', new PublishPayload('Hola mundo'), []);

        $this->assertSame('PAGE1_POST1', $result->remoteId);
        $this->assertStringContainsString('PAGE1_POST1', (string) $result->remoteUrl);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/feed')
            && $r['message'] === 'Hola mundo'
            && $r['access_token'] === 'PAGE_TOKEN');
    }

    public function test_publica_imagen_en_pagina(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'fields=access_token')) {
                return Http::response(['access_token' => 'PAGE_TOKEN']);
            }
            if (str_contains($r->url(), '/photos')) {
                return Http::response(['id' => 'PHOTO1', 'post_id' => 'PAGE1_POST9']);
            }

            return Http::response([], 404);
        });

        $payload = new PublishPayload('Con foto', ['https://cdn.test/img.jpg']);
        $result = $this->provider()->publish($this->tokens(), 'PAGE1', $payload, []);

        $this->assertSame('PAGE1_POST9', $result->remoteId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1/photos')
            && $r['url'] === 'https://cdn.test/img.jpg');
    }

    public function test_metricas_de_cuenta(): void
    {
        Http::fake(function (Request $r) {
            $u = $r->url();
            if (str_contains($u, 'fields=access_token')) {
                return Http::response(['access_token' => 'PT']);
            }
            if (str_contains($u, 'fields=fan_count')) {
                return Http::response(['fan_count' => 1000, 'followers_count' => 1200]);
            }
            if (str_contains($u, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'page_impressions', 'values' => [['value' => 500]]],
                    ['name' => 'page_impressions_unique', 'values' => [['value' => 400]]],
                    ['name' => 'page_post_engagements', 'values' => [['value' => 90]]],
                ]]);
            }

            return Http::response([], 404);
        });

        $m = $this->provider()->fetchAccountMetrics($this->tokens(), 'PAGE1', []);

        $this->assertSame(1200, $m->followers);
        $this->assertSame(400, $m->reach);
        $this->assertSame(500, $m->impressions);
        $this->assertSame(90, $m->engagement);
    }

    public function test_metricas_de_post(): void
    {
        Http::fake(function (Request $r) {
            $u = $r->url();
            if (str_contains($u, 'fields=access_token')) {
                return Http::response(['access_token' => 'PT']);
            }
            if (str_contains($u, 'fields=likes.summary')) {
                return Http::response([
                    'likes' => ['summary' => ['total_count' => 10]],
                    'comments' => ['summary' => ['total_count' => 3]],
                    'shares' => ['count' => 2],
                ]);
            }
            if (str_contains($u, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'post_impressions', 'values' => [['value' => 800]]],
                    ['name' => 'post_impressions_unique', 'values' => [['value' => 600]]],
                    ['name' => 'post_clicks', 'values' => [['value' => 40]]],
                ]]);
            }

            return Http::response([], 404);
        });

        $m = $this->provider()->fetchPostMetrics($this->tokens(), 'PAGE1_POST1', []);

        $this->assertSame(800, $m->impressions);
        $this->assertSame(600, $m->reach);
        $this->assertSame(10, $m->likes);
        $this->assertSame(3, $m->comments);
        $this->assertSame(2, $m->shares);
        $this->assertSame(40, $m->clicks);
    }

    public function test_lee_comentarios_como_conversaciones(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'fields=access_token')) {
                return Http::response(['access_token' => 'PT']);
            }
            if (str_contains($r->url(), '/feed')) {
                return Http::response(['data' => [[
                    'comments' => ['data' => [[
                        'id' => 'PAGE1_POST1_C1',
                        'message' => '¿Tienen envío?',
                        'created_time' => '2026-09-20T10:00:00+0000',
                        'from' => ['id' => 'u1', 'name' => 'Ana'],
                    ]]],
                ]]]);
            }

            return Http::response([], 404);
        });

        $threads = $this->provider()->fetchConversations($this->tokens(), 'PAGE1', []);

        $this->assertCount(1, $threads);
        $this->assertSame('PAGE1_POST1_C1', $threads[0]->externalId);
        $this->assertSame('Ana', $threads[0]->participantName);
        $this->assertSame('¿Tienen envío?', $threads[0]->messages[0]->body);
    }

    public function test_responde_a_un_comentario(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'fields=access_token')) {
                return Http::response(['access_token' => 'PAGE_TOKEN']);
            }
            if (str_contains($r->url(), '/comments')) {
                return Http::response(['id' => 'C1_REPLY']);
            }

            return Http::response([], 404);
        });

        $result = $this->provider()->replyToConversation($this->tokens(), 'PAGE1_POST1_C1', 'Sí, con gusto.', []);

        $this->assertSame('C1_REPLY', $result->externalId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PAGE1_POST1_C1/comments')
            && $r['message'] === 'Sí, con gusto.'
            && $r['access_token'] === 'PAGE_TOKEN');
    }

    public function test_error_de_meta_en_publicacion_lanza_excepcion(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'fields=access_token')) {
                return Http::response(['access_token' => 'PT']);
            }

            return Http::response(['error' => ['message' => 'Permiso denegado']], 403);
        });

        $this->expectException(\RuntimeException::class);
        $this->provider()->publish($this->tokens(), 'PAGE1', new PublishPayload('x'), []);
    }
}
