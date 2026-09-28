<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\MediaFile;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\XProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Adaptador de X (API v2) contra respuestas simuladas.
 */
class XProviderTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'XID', 'client_secret' => 'XSECRET'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function provider(): XProvider
    {
        return new XProvider();
    }

    private function file(string $mime, string $contents): MediaFile
    {
        return new MediaFile('https://files.test/x', $mime, strlen($contents), function () use ($contents) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $contents);
            rewind($stream);

            return $stream;
        });
    }

    public function test_autoriza_con_pkce_y_canjea_con_basic_auth(): void
    {
        $url = $this->provider()->authorizeUrl('https://app.test/cb', 'S', 'CHALLENGE', $this->provider()->defaultScopes(), self::CREDENTIALS);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://x.com/i/oauth2/authorize?', $url);
        $this->assertSame('tweet.read tweet.write users.read media.write offline.access', $query['scope']);
        $this->assertSame('CHALLENGE', $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);

        Http::fake(['api.x.com/2/oauth2/token' => Http::response([
            'token_type' => 'bearer', 'expires_in' => 7200, 'access_token' => 'AT', 'refresh_token' => 'RT', 'scope' => 'tweet.read tweet.write users.read media.write offline.access',
        ])]);
        $tokens = $this->provider()->exchangeCode('CODE', 'https://app.test/cb', 'VERIFIER', self::CREDENTIALS);

        $this->assertSame('RT', $tokens->refreshToken);
        $this->assertTrue($tokens->expiresAt?->between(now()->addMinutes(119), now()->addMinutes(121)));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic ' . base64_encode('XID:XSECRET'))
            && $r['code_verifier'] === 'VERIFIER' && ! isset($r['client_secret']));
    }

    public function test_renovar_devuelve_el_nuevo_refresh_token_de_un_solo_uso(): void
    {
        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 7200])]);

        $tokens = $this->provider()->refreshTokens('RT1', self::CREDENTIALS);

        $this->assertSame('RT2', $tokens->refreshToken);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'RT1');
    }

    public function test_longitud_ponderada_urls_23_y_emojis_2(): void
    {
        $provider = $this->provider();

        $this->assertSame(4, $provider->textLength('Hola'));
        $this->assertSame(6 + 23, $provider->textLength('Mira: https://ejemplo.com/una/ruta/muy/larga/que/no/importa'));
        $this->assertSame(2, $provider->textLength('😀'));
        $this->assertSame(2, $provider->textLength('👍🏽')); // emoji con modificador: un grafema
        $this->assertSame(4, $provider->textLength('日本'));
    }

    public function test_publica_texto_y_guarda_el_id(): void
    {
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '1445880548472328192', 'text' => 'Hola']], 201)]);
        $saved = [];
        $checkpoint = new PublishCheckpoint([], function (array $data) use (&$saved): void {
            $saved = $data;
        });

        $result = $this->provider()->publish(new OAuthTokens('AT'), 'U1', new PublishPayload('Hola', checkpoint: $checkpoint), self::CREDENTIALS);

        $this->assertSame('1445880548472328192', $result->remoteId);
        $this->assertSame('https://x.com/i/web/status/1445880548472328192', $result->remoteUrl);
        $this->assertSame('1445880548472328192', $saved['x.post_id'] ?? null);
        Http::assertSent(fn (Request $r) => $r['text'] === 'Hola' && ! isset($r['media']) && $r->hasHeader('Authorization', 'Bearer AT'));
    }

    public function test_publica_imagenes_subidas_de_una_vez(): void
    {
        $n = 0;
        Http::fake(function (Request $r) use (&$n) {
            return str_ends_with($r->url(), '/2/media/upload')
                ? Http::response(['data' => ['id' => 'M' . (++$n), 'media_key' => '3_M' . $n]])
                : Http::response(['data' => ['id' => '99']], 201);
        });

        $this->provider()->publish(new OAuthTokens('AT'), 'U1', new PublishPayload(
            body: 'Fotos',
            mediaFiles: [$this->file('image/png', 'a'), $this->file('image/jpeg', 'b')],
        ), self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/2/media/upload') && $r->isMultipart());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/2/tweets') && $r['media']['media_ids'] === ['M1', 'M2']);
    }

    public function test_video_por_partes_con_espera_de_procesamiento(): void
    {
        $status = ['pending', 'in_progress', 'succeeded'];
        Http::fake(function (Request $r) use (&$status) {
            return match (true) {
                str_ends_with($r->url(), '/media/upload/initialize') => Http::response(['data' => ['id' => 'V1', 'media_key' => '7_V1', 'expires_after_secs' => 86400]]),
                str_ends_with($r->url(), '/media/upload/V1/append') => Http::response(['data' => ['expires_at' => 1]]),
                str_ends_with($r->url(), '/media/upload/V1/finalize') => Http::response(['data' => ['id' => 'V1', 'processing_info' => ['state' => array_shift($status), 'check_after_secs' => 1]]]),
                str_contains($r->url(), '/media/upload?') => Http::response(['data' => ['id' => 'V1', 'processing_info' => ['state' => array_shift($status), 'check_after_secs' => 1]]]),
                str_ends_with($r->url(), '/2/tweets') => Http::response(['data' => ['id' => '100']], 201),
            };
        });

        $result = $this->provider()->publish(new OAuthTokens('AT'), 'U1', new PublishPayload(
            body: 'Video',
            mediaTypes: ['video'],
            mediaFiles: [$this->file('video/mp4', str_repeat('v', 10))],
        ), self::CREDENTIALS);

        $this->assertSame('100', $result->remoteId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/initialize') && $r['media_type'] === 'video/mp4' && $r['total_bytes'] === 10 && $r['media_category'] === 'tweet_video');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/2/tweets') && $r['media']['media_ids'] === ['V1']);
    }

    public function test_no_mezcla_video_con_imagenes_ni_repite_una_publicacion_creada(): void
    {
        try {
            $this->provider()->publish(new OAuthTokens('AT'), 'U1', new PublishPayload(
                body: 'x',
                mediaFiles: [$this->file('video/mp4', 'v'), $this->file('image/png', 'i')],
            ), self::CREDENTIALS);
            $this->fail('Debía rechazar la mezcla.');
        } catch (SocialProviderException) {
            Http::assertNothingSent();
        }

        $result = $this->provider()->publish(new OAuthTokens('AT'), 'U1', new PublishPayload(
            'x',
            checkpoint: new PublishCheckpoint(['x.post_id' => '555']),
        ), self::CREDENTIALS);
        $this->assertSame('555', $result->remoteId);
        Http::assertNothingSent();
    }

    public function test_errores_403_con_el_detalle_y_401_como_token_caducado(): void
    {
        Http::fake(['api.x.com/2/tweets' => Http::sequence()
            ->push(['title' => 'Forbidden', 'detail' => 'Creating posts with @mentions is not allowed for your access package.', 'type' => 'about:blank', 'status' => 403], 403)
            ->push(['title' => 'Unauthorized', 'type' => 'about:blank', 'status' => 401, 'detail' => 'Unauthorized'], 401)]);

        try {
            $this->provider()->publish(new OAuthTokens('AT'), 'U1', new PublishPayload('Hola @alguien'), self::CREDENTIALS);
            $this->fail('Debía fallar.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('@mentions is not allowed', $e->getMessage());
        }

        $this->expectException(SocialTokenExpiredException::class);
        $this->provider()->publish(new OAuthTokens('AT'), 'U1', new PublishPayload('Hola'), self::CREDENTIALS);
    }

    public function test_metricas_y_menciones(): void
    {
        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), '/2/users/me') => Http::response(['data' => ['id' => 'U1', 'username' => 'cafenorte', 'public_metrics' => ['followers_count' => 321, 'tweet_count' => 50]]]),
                str_contains($r->url(), '/2/tweets/100') => Http::response(['data' => ['id' => '100',
                    'public_metrics' => ['retweet_count' => 2, 'reply_count' => 3, 'like_count' => 10, 'quote_count' => 1, 'impression_count' => 400],
                    'non_public_metrics' => ['impression_count' => 450, 'url_link_clicks' => 7],
                ]]),
                str_contains($r->url(), '/2/users/U1/mentions') => Http::response([
                    'data' => [
                        ['id' => 'T1', 'text' => '@cafenorte ¿abren hoy?', 'author_id' => 'A1', 'created_at' => '2026-09-26T10:00:00.000Z'],
                        ['id' => 'T2', 'text' => 'propia', 'author_id' => 'U1'],
                    ],
                    'includes' => ['users' => [['id' => 'A1', 'username' => 'lucia']]],
                ]),
                str_ends_with($r->url(), '/2/tweets') => Http::response(['data' => ['id' => 'T3']], 201),
            };
        });

        $account = $this->provider()->fetchAccountMetrics(new OAuthTokens('AT'), 'U1', self::CREDENTIALS);
        $this->assertSame(321, $account->followers);
        $this->assertSame(50, $account->postsCount);

        $post = $this->provider()->fetchPostMetrics(new OAuthTokens('AT'), '100', self::CREDENTIALS);
        $this->assertSame(450, $post->impressions);
        $this->assertSame(3, $post->shares);
        $this->assertSame(7, $post->clicks);

        $threads = $this->provider()->fetchConversations(new OAuthTokens('AT'), 'U1', self::CREDENTIALS);
        $this->assertCount(1, $threads);
        $this->assertSame('mention', $threads[0]->type);
        $this->assertSame('@lucia', $threads[0]->participantName);

        $reply = $this->provider()->replyToConversation(new OAuthTokens('AT'), 'T1', 'Sí, hasta las 20 h', self::CREDENTIALS);
        $this->assertSame('T3', $reply->externalId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/2/tweets') && $r['reply']['in_reply_to_tweet_id'] === 'T1');
    }
}
