<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\ThreadsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Adaptador de Threads contra respuestas simuladas de graph.threads.net.
 */
class ThreadsProviderTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'TAPP', 'client_secret' => 'TSECRET'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function provider(): ThreadsProvider
    {
        return new ThreadsProvider();
    }

    public function test_autoriza_sin_pkce_con_scopes_separados_por_comas(): void
    {
        $url = $this->provider()->authorizeUrl('https://app.test/cb', 'S', null, $this->provider()->defaultScopes(), self::CREDENTIALS);

        $this->assertStringStartsWith('https://threads.com/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('threads_basic,threads_content_publish,threads_read_replies,threads_manage_replies,threads_manage_insights', $query['scope']);
        $this->assertFalse($this->provider()->usesPkce());
    }

    public function test_canjea_el_codigo_por_un_token_de_60_dias_que_se_renueva_consigo_mismo(): void
    {
        Http::fake([
            'graph.threads.net/oauth/access_token' => Http::response(['access_token' => 'SHORT', 'user_id' => 17841405793187218]),
            'graph.threads.net/access_token*' => Http::response(['access_token' => 'LONG', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'graph.threads.net/refresh_access_token*' => Http::response(['access_token' => 'LONG2', 'expires_in' => 5184000]),
        ]);

        $tokens = $this->provider()->exchangeCode('CODE', 'https://app.test/cb', null, self::CREDENTIALS);
        $this->assertSame('LONG', $tokens->accessToken);
        $this->assertSame('LONG', $tokens->refreshToken);
        $this->assertTrue($tokens->expiresAt?->between(now()->addDays(59), now()->addDays(61)));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'access_token?grant_type=th_exchange_token') && $r['access_token'] === 'SHORT' && $r['client_secret'] === 'TSECRET');

        $refreshed = $this->provider()->refreshTokens('LONG', self::CREDENTIALS);
        $this->assertSame('LONG2', $refreshed->accessToken);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'refresh_access_token') && $r['grant_type'] === 'th_refresh_token' && $r['access_token'] === 'LONG');
    }

    public function test_destino_es_el_perfil(): void
    {
        Http::fake(['graph.threads.net/v1.0/me*' => Http::response(['id' => '123', 'username' => 'cafenorte', 'name' => 'Café Norte'])]);

        $destinations = $this->provider()->fetchDestinations(new OAuthTokens('T'), self::CREDENTIALS);

        $this->assertSame('123', $destinations[0]->externalId);
        $this->assertSame('@cafenorte', $destinations[0]->name);
    }

    public function test_publica_texto_en_dos_pasos_y_guarda_el_id_antes_de_devolverlo(): void
    {
        Http::fake(function (Request $r) {
            return match (true) {
                str_ends_with($r->url(), '/123/threads') => Http::response(['id' => 'C1']),
                str_contains($r->url(), '/C1?') => Http::response(['id' => 'C1', 'status' => 'FINISHED']),
                str_ends_with($r->url(), '/123/threads_publish') => Http::response(['id' => 'M1']),
                str_contains($r->url(), '/M1?') => Http::response(['permalink' => 'https://www.threads.com/@cafenorte/post/abc']),
            };
        });
        $saved = [];
        $checkpoint = new PublishCheckpoint([], function (array $data) use (&$saved): void {
            $saved = $data;
        });

        $result = $this->provider()->publish(new OAuthTokens('T'), '123', new PublishPayload('Hola Threads', checkpoint: $checkpoint), self::CREDENTIALS);

        $this->assertSame('M1', $result->remoteId);
        $this->assertSame('https://www.threads.com/@cafenorte/post/abc', $result->remoteUrl);
        $this->assertSame('M1', $saved['threads.media_id'] ?? null);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/123/threads') && $r['media_type'] === 'TEXT' && $r['text'] === 'Hola Threads' && $r['access_token'] === 'T');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads_publish') && $r['creation_id'] === 'C1');
    }

    public function test_carrusel_crea_los_hijos_espera_los_videos_y_publica(): void
    {
        $status = ['K1' => ['IN_PROGRESS', 'FINISHED'], 'K2' => ['FINISHED'], 'CAR' => ['FINISHED']];
        Http::fake(function (Request $r) use (&$status) {
            if (str_ends_with($r->url(), '/123/threads')) {
                return Http::response(['id' => match ($r['media_type']) {
                    'VIDEO' => 'K1',
                    'IMAGE' => 'K2',
                    'CAROUSEL' => 'CAR',
                }]);
            }
            foreach ($status as $id => $sequence) {
                if (str_contains($r->url(), "/{$id}?")) {
                    return Http::response(['status' => count($sequence) > 1 ? array_shift($status[$id]) : $sequence[0]]);
                }
            }

            return str_ends_with($r->url(), '/threads_publish')
                ? Http::response(['id' => 'M2'])
                : Http::response(['permalink' => 'https://www.threads.com/p/2']);
        });

        $result = $this->provider()->publish(new OAuthTokens('T'), '123', new PublishPayload(
            body: 'Álbum',
            mediaUrls: ['https://files.test/v.mp4', 'https://files.test/i.png'],
            mediaTypes: ['video', 'image'],
        ), self::CREDENTIALS);

        $this->assertSame('M2', $result->remoteId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/123/threads') && $r['media_type'] === 'VIDEO' && $r['is_carousel_item'] === 'true');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/123/threads') && $r['media_type'] === 'CAROUSEL' && $r['children'] === 'K1,K2' && $r['text'] === 'Álbum');
    }

    public function test_video_con_error_de_procesamiento_olvida_el_contenedor(): void
    {
        Http::fake(function (Request $r) {
            return str_ends_with($r->url(), '/123/threads')
                ? Http::response(['id' => 'V1'])
                : Http::response(['status' => 'ERROR', 'error_message' => 'INVALID_DURATION']);
        });
        $saved = [];
        $checkpoint = new PublishCheckpoint([], function (array $data) use (&$saved): void {
            $saved = $data;
        });

        try {
            $this->provider()->publish(new OAuthTokens('T'), '123', new PublishPayload(
                body: 'x',
                mediaUrls: ['https://files.test/v.mp4'],
                mediaTypes: ['video'],
                checkpoint: $checkpoint,
            ), self::CREDENTIALS);
            $this->fail('Debía fallar.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('INVALID_DURATION', $e->getMessage());
        }
        $this->assertSame([], $saved['threads.containers'] ?? []);
    }

    public function test_token_caducado_codigo_190(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        $this->expectException(SocialTokenExpiredException::class);
        $this->provider()->fetchDestinations(new OAuthTokens('T'), self::CREDENTIALS);
    }

    public function test_metricas_de_cuenta_y_de_publicacion(): void
    {
        Http::fake([
            'graph.threads.net/v1.0/123/threads_insights*' => Http::response(['data' => [
                ['name' => 'views', 'values' => [['value' => 40], ['value' => 60]]],
                ['name' => 'likes', 'total_value' => ['value' => 7]],
                ['name' => 'replies', 'total_value' => ['value' => 3]],
                ['name' => 'followers_count', 'total_value' => ['value' => 250]],
            ]]),
            'graph.threads.net/v1.0/M1/insights*' => Http::response(['data' => [
                ['name' => 'views', 'values' => [['value' => 500]]],
                ['name' => 'likes', 'values' => [['value' => 20]]],
                ['name' => 'replies', 'values' => [['value' => 4]]],
                ['name' => 'reposts', 'values' => [['value' => 2]]],
            ]]),
        ]);

        $account = $this->provider()->fetchAccountMetrics(new OAuthTokens('T'), '123', self::CREDENTIALS);
        $this->assertSame(250, $account->followers);
        $this->assertSame(100, $account->impressions);
        $this->assertSame(10, $account->engagement);

        $post = $this->provider()->fetchPostMetrics(new OAuthTokens('T'), 'M1', self::CREDENTIALS);
        $this->assertSame(500, $post->impressions);
        $this->assertSame(20, $post->likes);
        $this->assertSame(4, $post->comments);
        $this->assertSame(2, $post->shares);
    }

    public function test_respuestas_al_inbox_y_respuesta(): void
    {
        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), '/123/threads?') => Http::response(['data' => [['id' => 'P1']]]),
                str_contains($r->url(), '/P1/replies') => Http::response(['data' => [
                    ['id' => 'R1', 'text' => '¿Abren el domingo?', 'username' => 'lucia', 'timestamp' => '2026-09-20T10:00:00+0000'],
                    ['id' => 'R2', 'text' => 'Sí', 'username' => 'cafenorte', 'is_reply_owned_by_me' => true],
                ]]),
                str_ends_with($r->url(), '/me/threads') => Http::response(['id' => 'RC']),
                str_contains($r->url(), '/RC?') => Http::response(['status' => 'FINISHED']),
                str_ends_with($r->url(), '/me/threads_publish') => Http::response(['id' => 'R3']),
            };
        });

        $threads = $this->provider()->fetchConversations(new OAuthTokens('T'), '123', self::CREDENTIALS);
        $this->assertCount(1, $threads);
        $this->assertSame('@lucia', $threads[0]->participantName);

        $reply = $this->provider()->replyToConversation(new OAuthTokens('T'), 'R1', 'Sí, de 9 a 14', self::CREDENTIALS);
        $this->assertSame('R3', $reply->externalId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/me/threads') && $r['reply_to_id'] === 'R1' && $r['text'] === 'Sí, de 9 a 14');
    }

    public function test_probar_conexion_con_app_token(): void
    {
        Http::fake(['graph.threads.net/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'TH|TAPP|x', 'token_type' => 'bearer'])
            ->push(['error' => ['message' => 'Invalid client_secret', 'code' => 1]], 400)]);

        $this->provider()->verifyCredentials(self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'TAPP');

        $this->expectException(SocialProviderException::class);
        $this->provider()->verifyCredentials(self::CREDENTIALS);
    }
}
