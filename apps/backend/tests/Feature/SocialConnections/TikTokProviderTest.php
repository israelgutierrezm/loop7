<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\MediaFile;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\TikTokProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Adaptador de TikTok contra respuestas simuladas de open.tiktokapis.com.
 */
class TikTokProviderTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'CKEY', 'client_secret' => 'CSECRET'];

    private const OPTIONS = ['privacy_level' => 'SELF_ONLY', 'allow_comment' => true, 'consent' => true];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function provider(): TikTokProvider
    {
        return new TikTokProvider();
    }

    private function video(int $size = 12): MediaFile
    {
        return new MediaFile('https://files.test/v.mp4', 'video/mp4', $size, function () use ($size) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, str_repeat('v', $size));
            rewind($stream);

            return $stream;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function creatorInfo(array $privacy = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY']): array
    {
        return ['data' => [
            'creator_nickname' => 'Café Norte', 'creator_username' => 'cafenorte',
            'privacy_level_options' => $privacy, 'comment_disabled' => false, 'duet_disabled' => true,
            'stitch_disabled' => false, 'max_video_post_duration_sec' => 600,
        ], 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'L']];
    }

    public function test_autoriza_con_client_key_sin_pkce_y_canjea_tokens_de_24_horas(): void
    {
        $url = $this->provider()->authorizeUrl('https://app.test/cb', 'S', null, $this->provider()->defaultScopes(), self::CREDENTIALS);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize/?', $url);
        $this->assertSame('CKEY', $query['client_key']);
        $this->assertSame('user.info.basic,user.info.stats,video.publish,video.list', $query['scope']);

        Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response([
            'access_token' => 'act.1', 'expires_in' => 86400, 'open_id' => 'OID', 'refresh_expires_in' => 31536000,
            'refresh_token' => 'rft.1', 'scope' => 'user.info.basic,video.publish', 'token_type' => 'Bearer',
        ])]);
        $tokens = $this->provider()->exchangeCode('CODE', 'https://app.test/cb', null, self::CREDENTIALS);

        $this->assertSame('act.1', $tokens->accessToken);
        $this->assertSame('rft.1', $tokens->refreshToken);
        $this->assertSame(['user.info.basic', 'video.publish'], $tokens->scopes);
        Http::assertSent(fn (Request $r) => $r['client_key'] === 'CKEY' && $r['client_secret'] === 'CSECRET' && ! isset($r['client_id']));
    }

    public function test_opciones_de_publicacion_desde_creator_info(): void
    {
        Http::fake(['open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response($this->creatorInfo())]);

        $options = $this->provider()->publishOptions(new OAuthTokens('T'), 'OID', self::CREDENTIALS);

        $this->assertSame('Café Norte', $options['creator_nickname']);
        $this->assertSame(['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'], $options['privacy_level_options']);
        $this->assertTrue($options['duet_disabled']);
        $this->assertTrue($options['can_post']);
    }

    public function test_cuenta_bloqueada_llega_con_http_200_y_codigo_de_error(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'error' => ['code' => 'spam_risk_too_many_posts', 'message' => '', 'log_id' => 'L']])]);

        $options = $this->provider()->publishOptions(new OAuthTokens('T'), 'OID', self::CREDENTIALS);

        $this->assertFalse($options['can_post']);
        $this->assertStringContainsString('límite diario', (string) $options['blocked_reason']);
    }

    public function test_valida_las_opciones_obligatorias(): void
    {
        $provider = $this->provider();

        $this->assertCount(2, $provider->optionErrors([])); // privacidad + consentimiento
        $this->assertSame([], $provider->optionErrors(self::OPTIONS));
        $this->assertNotSame([], $provider->optionErrors([...self::OPTIONS, 'commercial' => true]));
        $this->assertNotSame([], $provider->optionErrors([...self::OPTIONS, 'commercial' => true, 'brand_content' => true]));
        $this->assertSame([], $provider->optionErrors([...self::OPTIONS, 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'commercial' => true, 'brand_content' => true]));
    }

    public function test_publica_video_por_partes_con_las_opciones_elegidas(): void
    {
        $statuses = ['PROCESSING_UPLOAD', 'PUBLISH_COMPLETE'];
        Http::fake(function (Request $r) use (&$statuses) {
            return match (true) {
                str_contains($r->url(), 'creator_info') => Http::response($this->creatorInfo()),
                str_contains($r->url(), 'video/init') => Http::response(['data' => [
                    'publish_id' => 'v_pub_file~1', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=9',
                ], 'error' => ['code' => 'ok']]),
                str_contains($r->url(), 'open-upload') => Http::response('', 201),
                str_contains($r->url(), 'status/fetch') => Http::response(['data' => [
                    'status' => array_shift($statuses) ?? 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [],
                ], 'error' => ['code' => 'ok']]),
            };
        });
        $saved = [];
        $checkpoint = new PublishCheckpoint([], function (array $data) use (&$saved): void {
            $saved = $data;
        });

        $result = $this->provider()->publish(new OAuthTokens('T'), 'OID', new PublishPayload(
            body: 'Nuevo café #coffee',
            mediaTypes: ['video'],
            checkpoint: $checkpoint,
            mediaFiles: [$this->video()],
            options: self::OPTIONS,
        ), self::CREDENTIALS);

        // Privado: sin id público, se guarda el de la publicación y el enlace al perfil.
        $this->assertSame('v_pub_file~1', $result->remoteId);
        $this->assertSame('https://www.tiktok.com/@cafenorte', $result->remoteUrl);
        $this->assertSame('v_pub_file~1', $saved['tiktok.publish_id'] ?? null);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'video/init')
            && $r['post_info']['privacy_level'] === 'SELF_ONLY'
            && $r['post_info']['title'] === 'Nuevo café #coffee'
            && $r['post_info']['disable_comment'] === false
            && $r['post_info']['disable_duet'] === true // desmarcado por defecto y bloqueado por la cuenta
            && $r['post_info']['brand_content_toggle'] === false
            && $r['source_info']['source'] === 'FILE_UPLOAD'
            && $r['source_info']['video_size'] === 12
            && $r['source_info']['total_chunk_count'] === 1);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'open-upload')
            && $r->hasHeader('Content-Range', 'bytes 0-11/12') && $r->body() === str_repeat('v', 12));
    }

    public function test_video_grande_en_partes_de_10_mb_con_la_ultima_mas_grande(): void
    {
        $provider = $this->provider();
        $method = new \ReflectionMethod($provider, 'chunking');

        // Hasta 64 MB, una sola parte; por encima, partes de 10 MB y la última absorbe el resto.
        $this->assertSame([25_000_123, 1], $method->invoke($provider, 25_000_123));
        $this->assertSame([10_000_000, 7], $method->invoke($provider, 70_000_001));
    }

    public function test_publicacion_publica_devuelve_el_id_y_el_enlace_del_video(): void
    {
        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), 'status/fetch') => Http::response(['data' => [
                    'status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7291234567890123456],
                ], 'error' => ['code' => 'ok']]),
                str_contains($r->url(), 'creator_info') => Http::response($this->creatorInfo()),
            };
        });
        // Reintento con la publicación ya iniciada: sólo consulta el estado, no sube otra vez.
        $checkpoint = new PublishCheckpoint(['tiktok.publish_id' => 'v_pub_file~2']);

        $result = $this->provider()->publish(new OAuthTokens('T'), 'OID', new PublishPayload(
            body: 'x',
            mediaTypes: ['video'],
            checkpoint: $checkpoint,
            mediaFiles: [$this->video()],
            options: self::OPTIONS,
        ), self::CREDENTIALS);

        $this->assertSame('7291234567890123456', $result->remoteId);
        $this->assertSame('https://www.tiktok.com/@cafenorte/video/7291234567890123456', $result->remoteUrl);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'video/init'));
    }

    public function test_rechaza_privacidad_no_disponible_y_fallo_de_procesamiento(): void
    {
        Http::fake(['*creator_info*' => Http::response($this->creatorInfo(['FOLLOWER_OF_CREATOR', 'SELF_ONLY']))]);

        try {
            $this->provider()->publish(new OAuthTokens('T'), 'OID', new PublishPayload(
                body: 'x',
                mediaTypes: ['video'],
                mediaFiles: [$this->video()],
                options: [...self::OPTIONS, 'privacy_level' => 'PUBLIC_TO_EVERYONE'],
            ), self::CREDENTIALS);
            $this->fail('Debía rechazar la privacidad.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('privacidad elegida ya no está disponible', $e->getMessage());
        }

        Http::fake(['*status/fetch*' => Http::response(['data' => ['status' => 'FAILED', 'fail_reason' => 'duration_check_failed'], 'error' => ['code' => 'ok']])]);
        $saved = ['tiktok.publish_id' => 'v_pub_file~3'];
        $checkpoint = new PublishCheckpoint($saved, function (array $data) use (&$saved): void {
            $saved = $data;
        });
        try {
            $this->provider()->publish(new OAuthTokens('T'), 'OID', new PublishPayload(
                body: 'x',
                mediaTypes: ['video'],
                checkpoint: $checkpoint,
                mediaFiles: [$this->video()],
                options: self::OPTIONS,
            ), self::CREDENTIALS);
            $this->fail('Debía fallar.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('duración', $e->getMessage());
        }
        $this->assertArrayNotHasKey('tiktok.publish_id', $saved); // el reintento empezará de cero
    }

    public function test_sin_opciones_obligatorias_no_publica(): void
    {
        $this->expectException(SocialProviderException::class);
        $this->provider()->publish(new OAuthTokens('T'), 'OID', new PublishPayload(
            body: 'x',
            mediaTypes: ['video'],
            mediaFiles: [$this->video()],
        ), self::CREDENTIALS);
    }

    public function test_metricas_de_cuenta_y_de_video_publico(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['follower_count' => 1200, 'likes_count' => 5000, 'video_count' => 42]], 'error' => ['code' => 'ok']]),
            'open.tiktokapis.com/v2/video/query/*' => Http::response(['data' => ['videos' => [['id' => '7291', 'view_count' => 900, 'like_count' => 80, 'comment_count' => 5, 'share_count' => 3]]], 'error' => ['code' => 'ok']]),
        ]);

        $account = $this->provider()->fetchAccountMetrics(new OAuthTokens('T'), 'OID', self::CREDENTIALS);
        $this->assertSame(1200, $account->followers);
        $this->assertSame(42, $account->postsCount);

        $post = $this->provider()->fetchPostMetrics(new OAuthTokens('T'), '7291', self::CREDENTIALS);
        $this->assertSame(900, $post->impressions);
        $this->assertSame(80, $post->likes);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'video/query') && $r['filters']['video_ids'] === ['7291']);

        $this->assertSame([], $this->provider()->fetchConversations(new OAuthTokens('T'), 'OID', self::CREDENTIALS));
    }

    public function test_token_invalido_marca_la_conexion_como_expirada(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'access_token_invalid', 'message' => 'The access token is invalid']], 401)]);

        $this->expectException(SocialTokenExpiredException::class);
        $this->provider()->fetchDestinations(new OAuthTokens('T'), self::CREDENTIALS);
    }
}
