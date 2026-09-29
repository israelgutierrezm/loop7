<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\DeletesRemotePosts;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\FacebookProvider;
use App\Modules\SocialConnections\Providers\InstagramProvider;
use App\Modules\SocialConnections\Providers\LinkedInProvider;
use App\Modules\SocialConnections\Providers\ThreadsProvider;
use App\Modules\SocialConnections\Providers\TikTokProvider;
use App\Modules\SocialConnections\Providers\XProvider;
use App\Modules\SocialConnections\Providers\YouTubeProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Borrar una publicación en cada red (docs/06): método, URL y cabeceras de
 * cada API; «ya no existe» cuenta como borrada; permisos y tokens, como error.
 */
class RemotePostDeletionProvidersTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'APP', 'client_secret' => 'SECRET'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_solo_las_redes_que_lo_permiten_implementan_el_borrado(): void
    {
        foreach ([FacebookProvider::class, ThreadsProvider::class, XProvider::class, LinkedInProvider::class, YouTubeProvider::class] as $class) {
            $this->assertInstanceOf(DeletesRemotePosts::class, new $class(), $class);
        }
        // Sin borrado en sus APIs de publicación.
        $this->assertNotInstanceOf(DeletesRemotePosts::class, new InstagramProvider());
        $this->assertNotInstanceOf(DeletesRemotePosts::class, new TikTokProvider());
    }

    public function test_facebook_borra_con_el_token_de_la_pagina(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        (new FacebookProvider())->deleteRemotePost(new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'), 'PAGE1_POST9', self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), 'graph.facebook.com/')
            && str_contains($r->url(), '/PAGE1_POST9?access_token=PAGE_TOKEN'));
    }

    public function test_facebook_da_por_borrada_la_que_ya_no_existe(): void
    {
        $gone = ['error' => ['message' => 'Unsupported delete request.', 'code' => 100, 'error_subcode' => 33]];
        Http::fake(['graph.facebook.com/*' => Http::response($gone, 400)]);

        (new FacebookProvider())->deleteRemotePost(new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'), 'PAGE1_POST9', self::CREDENTIALS);

        // Comprobó que no existe antes de darla por borrada.
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'PAGE1_POST9'));
    }

    public function test_facebook_no_la_da_por_borrada_si_sigue_existiendo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Unsupported delete request.', 'code' => 100, 'error_subcode' => 33]], 400)
            ->push(['id' => 'PAGE1_POST9'])]);

        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('Unsupported delete request');

        (new FacebookProvider())->deleteRemotePost(new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'), 'PAGE1_POST9', self::CREDENTIALS);
    }

    public function test_facebook_token_caducado(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session expired', 'code' => 190]], 400)]);

        $this->expectException(SocialTokenExpiredException::class);

        (new FacebookProvider())->deleteRemotePost(new OAuthTokens('USER', destinationToken: 'PAGE_TOKEN'), 'PAGE1_POST9', self::CREDENTIALS);
    }

    public function test_threads_borra_y_pide_el_permiso_threads_delete(): void
    {
        Http::fake(['graph.threads.net/*' => Http::sequence()
            ->push(['success' => true, 'deleted_id' => 'T1'])
            ->push(['error' => ['message' => 'Application does not have permission for this action', 'code' => 10]], 403)]);
        $provider = new ThreadsProvider();

        $provider->deleteRemotePost(new OAuthTokens('TT'), 'T1', self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'graph.threads.net/v1.0/T1?access_token=TT'));

        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('threads_delete');
        $provider->deleteRemotePost(new OAuthTokens('TT'), 'T2', self::CREDENTIALS);
    }

    public function test_threads_da_por_borrada_la_que_ya_no_existe(): void
    {
        $gone = ['error' => ['message' => 'Object does not exist', 'code' => 100, 'error_subcode' => 33]];
        Http::fake(['graph.threads.net/*' => Http::response($gone, 400)]);

        (new ThreadsProvider())->deleteRemotePost(new OAuthTokens('TT'), 'T1', self::CREDENTIALS);

        Http::assertSentCount(2);
    }

    public function test_x_borra_y_tolera_la_que_ya_no_existe(): void
    {
        Http::fake(['api.x.com/2/tweets/*' => Http::sequence()
            ->push(['data' => ['deleted' => true]])
            ->push(['title' => 'Not Found Error', 'type' => 'https://api.x.com/2/problems/resource-not-found'], 404)
            ->push(['errors' => [['type' => 'https://api.x.com/2/problems/resource-not-found']]])]);
        $provider = new XProvider();

        $provider->deleteRemotePost(new OAuthTokens('XT'), '1790000000000000001', self::CREDENTIALS);
        $provider->deleteRemotePost(new OAuthTokens('XT'), '1790000000000000002', self::CREDENTIALS);
        $provider->deleteRemotePost(new OAuthTokens('XT'), '1790000000000000003', self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.x.com/2/tweets/1790000000000000001'
            && $r->hasHeader('Authorization', 'Bearer XT'));
    }

    public function test_x_rechaza_si_no_es_de_la_cuenta_o_no_confirma(): void
    {
        Http::fake(['api.x.com/2/tweets/*' => Http::sequence()
            ->push(['title' => 'Forbidden', 'detail' => 'You are not allowed to delete this Tweet', 'type' => 'https://api.x.com/2/problems/not-authorized-for-resource'], 403)
            ->push(['data' => ['deleted' => false]])]);
        $provider = new XProvider();

        try {
            $provider->deleteRemotePost(new OAuthTokens('XT'), '1', self::CREDENTIALS);
            $this->fail('Debía rechazarse.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        $this->expectExceptionMessage('X no confirmó el borrado');
        $provider->deleteRemotePost(new OAuthTokens('XT'), '2', self::CREDENTIALS);
    }

    public function test_linkedin_borra_con_el_urn_codificado(): void
    {
        Http::fake(['api.linkedin.com/rest/posts/*' => Http::response(null, 204)]);

        (new LinkedInProvider())->deleteRemotePost(new OAuthTokens('LT'), 'urn:li:share:6844785523593134080', self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.linkedin.com/rest/posts/urn%3Ali%3Ashare%3A6844785523593134080'
            && $r->hasHeader('X-RestLi-Method', 'DELETE')
            && $r->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $r->hasHeader('LinkedIn-Version'));
    }

    public function test_linkedin_sin_permiso_es_un_error(): void
    {
        Http::fake(['api.linkedin.com/rest/posts/*' => Http::response(['message' => 'Not enough permissions to access: partnerApiPostsExternal.DELETE'], 403)]);

        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('Not enough permissions');

        (new LinkedInProvider())->deleteRemotePost(new OAuthTokens('LT'), 'urn:li:ugcPost:1', self::CREDENTIALS);
    }

    public function test_youtube_borra_el_video_y_tolera_el_que_ya_no_existe(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::sequence()
            ->push(null, 204)
            ->push(['error' => ['code' => 404, 'message' => 'Video not found', 'errors' => [['reason' => 'videoNotFound']]]], 404)
            ->push(['error' => ['code' => 403, 'message' => 'Forbidden', 'errors' => [['reason' => 'forbidden']]]], 403)]);
        $provider = new YouTubeProvider();

        $provider->deleteRemotePost(new OAuthTokens('YT'), 'VID1', self::CREDENTIALS);
        $provider->deleteRemotePost(new OAuthTokens('YT'), 'VID2', self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://www.googleapis.com/youtube/v3/videos?id=VID1');

        // Un 403 no es un token caducado: es un error de permiso.
        try {
            $provider->deleteRemotePost(new OAuthTokens('YT'), 'VID3', self::CREDENTIALS);
            $this->fail('Debía rechazarse.');
        } catch (SocialTokenExpiredException) {
            $this->fail('Un 403 no debe marcar la conexión como caducada.');
        } catch (SocialProviderException $e) {
            $this->assertStringContainsString('Forbidden', $e->getMessage());
        }
    }
}
