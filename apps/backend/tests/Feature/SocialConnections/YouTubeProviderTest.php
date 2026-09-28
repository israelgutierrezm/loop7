<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\MediaFile;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\YouTubeProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adaptador de YouTube contra respuestas simuladas de Google.
 */
class YouTubeProviderTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'GID.apps.googleusercontent.com', 'client_secret' => 'GSECRET'];

    private const OPTIONS = ['privacy_status' => 'unlisted', 'made_for_kids' => false];

    private const SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=U1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function provider(): YouTubeProvider
    {
        return new YouTubeProvider();
    }

    private function video(string $contents = 'videobytes'): MediaFile
    {
        return new MediaFile('https://files.test/v.mp4', 'video/mp4', strlen($contents), function () use ($contents) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $contents);
            rewind($stream);

            return $stream;
        });
    }

    public function test_autoriza_offline_con_pkce_y_scopes_de_google(): void
    {
        $url = $this->provider()->authorizeUrl('https://app.test/cb', 'S', 'CH', $this->provider()->defaultScopes(), self::CREDENTIALS);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('CH', $query['code_challenge']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/youtube.upload', $query['scope']);
    }

    public function test_canal_como_destino_y_error_si_no_hay_canal(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::sequence()
            ->push(['items' => [['id' => 'UC1', 'snippet' => ['title' => 'Café Norte TV', 'customUrl' => '@cafenorte']]]])
            ->push(['items' => []])]);

        $destinations = $this->provider()->fetchDestinations(new OAuthTokens('T'), self::CREDENTIALS);
        $this->assertSame('UC1', $destinations[0]->externalId);
        $this->assertSame('channel', $destinations[0]->type);

        $this->expectException(SocialProviderException::class);
        $this->provider()->fetchAccount(new OAuthTokens('T'), self::CREDENTIALS);
    }

    public function test_opciones_obligatorias(): void
    {
        $this->assertCount(2, $this->provider()->optionErrors([]));
        $this->assertSame([], $this->provider()->optionErrors(self::OPTIONS));
        $this->assertNotSame([], $this->provider()->optionErrors([...self::OPTIONS, 'title' => str_repeat('x', 101)]));
        $this->assertNotSame([], $this->provider()->optionErrors([...self::OPTIONS, 'title' => 'Mal <título>']));
    }

    public function test_sube_con_sesion_reanudable_y_metadatos_elegidos(): void
    {
        Http::fake(function (Request $r) {
            return match (true) {
                $r->method() === 'POST' && str_contains($r->url(), 'upload/youtube/v3/videos') => Http::response('', 200, ['Location' => self::SESSION]),
                $r->method() === 'PUT' && $r->url() === self::SESSION => Http::response(['id' => 'VID123', 'status' => ['uploadStatus' => 'uploaded']], 201),
            };
        });
        $saved = [];
        $checkpoint = new PublishCheckpoint([], function (array $data) use (&$saved): void {
            $saved = $data;
        });

        $result = $this->provider()->publish(new OAuthTokens('T'), 'UC1', new PublishPayload(
            body: "Receta de café frío\nPaso a paso <3",
            mediaTypes: ['video'],
            checkpoint: $checkpoint,
            mediaFiles: [$this->video()],
            title: 'Café frío',
            options: [...self::OPTIONS, 'synthetic_media' => true],
        ), self::CREDENTIALS);

        $this->assertSame('VID123', $result->remoteId);
        $this->assertSame('https://www.youtube.com/watch?v=VID123', $result->remoteUrl);
        $this->assertSame('VID123', $saved['youtube.video_id'] ?? null);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->hasHeader('X-Upload-Content-Length', '10')
            && $r->hasHeader('X-Upload-Content-Type', 'video/mp4')
            && $r['snippet']['title'] === 'Café frío'
            && $r['snippet']['description'] === "Receta de café frío\nPaso a paso 3"
            && $r['status']['privacyStatus'] === 'unlisted'
            && $r['status']['selfDeclaredMadeForKids'] === false
            && $r['status']['containsSyntheticMedia'] === true);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->body() === 'videobytes');
        // Sesión nueva: no se consulta su estado antes de subir.
        Http::assertSentCount(2);
    }

    public function test_reintento_retoma_la_subida_donde_quedo(): void
    {
        Http::fake(function (Request $r) {
            if ($r->method() === 'PUT' && $r->hasHeader('Content-Range', 'bytes */10')) {
                return Http::response('', 308, ['Range' => 'bytes=0-3']);
            }

            return Http::response(['id' => 'VID9'], 200);
        });
        $checkpoint = new PublishCheckpoint(['youtube.session' => ['uri' => self::SESSION, 'size' => 10, 'at' => now()->getTimestamp()]]);

        $result = $this->provider()->publish(new OAuthTokens('T'), 'UC1', new PublishPayload(
            body: 'x',
            mediaTypes: ['video'],
            checkpoint: $checkpoint,
            mediaFiles: [$this->video()],
            options: self::OPTIONS,
        ), self::CREDENTIALS);

        $this->assertSame('VID9', $result->remoteId);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Content-Range', 'bytes 4-9/10') && $r->body() === 'obytes');
    }

    public function test_sin_opciones_o_sin_video_no_publica(): void
    {
        try {
            $this->provider()->publish(new OAuthTokens('T'), 'UC1', new PublishPayload(body: 'x', mediaFiles: [$this->video()]), self::CREDENTIALS);
            $this->fail('Debía exigir las opciones.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('privacidad', $e->getMessage());
        }

        $this->expectException(SocialProviderException::class);
        $this->provider()->publish(new OAuthTokens('T'), 'UC1', new PublishPayload(body: 'x', options: self::OPTIONS), self::CREDENTIALS);
    }

    public function test_metricas_comentarios_respuesta_y_token_caducado(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC1', 'statistics' => ['subscriberCount' => '1500', 'viewCount' => '90000', 'videoCount' => '30']]]]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['statistics' => ['viewCount' => '800', 'likeCount' => '40', 'commentCount' => '6']]]]),
            'www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['items' => [
                ['snippet' => ['canReply' => true, 'topLevelComment' => ['id' => 'C1', 'snippet' => ['authorDisplayName' => 'Lucía', 'authorChannelId' => ['value' => 'UCX'], 'textDisplay' => '¡Qué rico!', 'publishedAt' => '2026-09-25T10:00:00Z']]]],
                ['snippet' => ['canReply' => true, 'topLevelComment' => ['id' => 'C2', 'snippet' => ['authorDisplayName' => 'Café Norte TV', 'authorChannelId' => ['value' => 'UC1'], 'textDisplay' => 'Gracias']]]],
            ]]),
            'www.googleapis.com/youtube/v3/comments*' => Http::response(['id' => 'C3']),
        ]);

        $account = $this->provider()->fetchAccountMetrics(new OAuthTokens('T'), 'UC1', self::CREDENTIALS);
        $this->assertSame(1500, $account->followers);
        $this->assertSame(30, $account->postsCount);

        $post = $this->provider()->fetchPostMetrics(new OAuthTokens('T'), 'VID123', self::CREDENTIALS);
        $this->assertSame(800, $post->impressions);
        $this->assertSame(6, $post->comments);

        $threads = $this->provider()->fetchConversations(new OAuthTokens('T'), 'UC1', self::CREDENTIALS);
        $this->assertCount(1, $threads);
        $this->assertSame('Lucía', $threads[0]->participantName);

        $reply = $this->provider()->replyToConversation(new OAuthTokens('T'), 'C1', '¡Gracias!', self::CREDENTIALS);
        $this->assertSame('C3', $reply->externalId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'comments?part=snippet') && $r['snippet']['parentId'] === 'C1');
    }

    public function test_token_caducado(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 401, 'message' => 'Request had invalid authentication credentials.', 'errors' => [['reason' => 'authError']]]], 401)]);

        $this->expectException(SocialTokenExpiredException::class);
        $this->provider()->fetchDestinations(new OAuthTokens('T'), self::CREDENTIALS);
    }
}
