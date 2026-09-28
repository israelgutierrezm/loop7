<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Modules\SocialConnections\Contracts\MediaFile;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Providers\LinkedInProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adaptador de LinkedIn contra respuestas simuladas (Http::fake): sin red.
 */
class LinkedInProviderTest extends TestCase
{
    private const CREDENTIALS = ['client_id' => 'CID', 'client_secret' => 'SECRET'];

    private const PERSON = 'urn:li:person:abc123';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function provider(): LinkedInProvider
    {
        return new LinkedInProvider();
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

    public function test_url_de_autorizacion_sin_pkce_y_scopes_de_alta_directa(): void
    {
        $url = $this->provider()->authorizeUrl('https://app.test/cb', 'STATE', null, $this->provider()->defaultScopes(), self::CREDENTIALS);

        $this->assertFalse($this->provider()->usesPkce());
        $this->assertStringStartsWith('https://www.linkedin.com/oauth/v2/authorization?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('openid profile email w_member_social', $query['scope']);
        $this->assertSame('CID', $query['client_id']);
        $this->assertArrayNotHasKey('code_challenge', $query);
    }

    public function test_canje_del_codigo_con_secreto_y_caducidad_de_60_dias(): void
    {
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'TOKEN', 'expires_in' => 5184000, 'scope' => 'openid,profile,w_member_social',
        ])]);

        $tokens = $this->provider()->exchangeCode('CODE', 'https://app.test/cb', null, self::CREDENTIALS);

        $this->assertSame('TOKEN', $tokens->accessToken);
        $this->assertNull($tokens->refreshToken);
        $this->assertTrue($tokens->expiresAt?->between(now()->addDays(59), now()->addDays(61)));
        $this->assertSame(['openid', 'profile', 'w_member_social'], $tokens->scopes);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code' && $r['client_secret'] === 'SECRET' && $r['code'] === 'CODE');
    }

    public function test_destinos_perfil_y_paginas_con_community_management(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'name' => 'Ana López']),
            'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
                ['organization' => 'urn:li:organization:42', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED'],
                ['organizationTarget' => 'urn:li:organization:43', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED'],
            ]]),
            'api.linkedin.com/rest/organizations/42' => Http::response(['localizedName' => 'Café Norte']),
            'api.linkedin.com/rest/organizations/43' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $onlyProfile = $this->provider()->fetchDestinations(new OAuthTokens('T', scopes: ['openid', 'w_member_social']), self::CREDENTIALS);
        $this->assertCount(1, $onlyProfile);
        $this->assertSame(self::PERSON, $onlyProfile[0]->externalId);
        $this->assertSame('profile', $onlyProfile[0]->type);

        $all = $this->provider()->fetchDestinations(new OAuthTokens('T', scopes: ['w_member_social', 'rw_organization_admin']), self::CREDENTIALS);
        $this->assertSame([self::PERSON, 'urn:li:organization:42', 'urn:li:organization:43'], array_map(fn ($d) => $d->externalId, $all));
        $this->assertSame('Café Norte', $all[1]->name);
        $this->assertSame('Página 43', $all[2]->name);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'organizationAcls') && $r->hasHeader('LinkedIn-Version', '202609'));
    }

    public function test_publica_texto_con_cabeceras_versionadas_y_escapa_el_formato(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:777'])]);

        $result = $this->provider()->publish(new OAuthTokens('T'), self::PERSON, new PublishPayload('Hola (mundo) #cafe @ana # fin'), [
            ...self::CREDENTIALS, 'api_version' => '202607',
        ]);

        $this->assertSame('urn:li:share:777', $result->remoteId);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:777/', $result->remoteUrl);
        Http::assertSent(fn (Request $r) => $r->hasHeader('LinkedIn-Version', '202607')
            && $r->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $r['author'] === self::PERSON
            && $r['commentary'] === 'Hola \\(mundo\\) #cafe \\@ana \\# fin'
            && $r['lifecycleState'] === 'PUBLISHED'
            && ! isset($r['content']));
    }

    public function test_publica_varias_imagenes_subiendolas_con_el_token(): void
    {
        $n = 0;
        Http::fake(function (Request $r) use (&$n) {
            return match (true) {
                str_contains($r->url(), 'images?action=initializeUpload') => Http::response(['value' => [
                    'uploadUrl' => 'https://www.linkedin.com/dms-uploads/img' . (++$n), 'image' => 'urn:li:image:I' . $n,
                ]]),
                str_contains($r->url(), 'dms-uploads/img') => Http::response('', 201),
                str_ends_with($r->url(), '/rest/posts') => Http::response('', 201, ['x-restli-id' => 'urn:li:share:9']),
            };
        });

        $this->provider()->publish(new OAuthTokens('T'), self::PERSON, new PublishPayload(
            body: 'Galería',
            mediaFiles: [$this->file('image/png', 'uno'), $this->file('image/jpeg', 'dos')],
        ), self::CREDENTIALS);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'dms-uploads/img1')
            && $r->hasHeader('Authorization', 'Bearer T') && $r->body() === 'uno');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/posts')
            && $r['content']['multiImage']['images'][1]['id'] === 'urn:li:image:I2');
    }

    public function test_publica_video_por_partes_y_finaliza_con_los_etag(): void
    {
        $video = str_repeat('v', 10);
        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), 'videos?action=initializeUpload') => Http::response(['value' => [
                    'video' => 'urn:li:video:V1',
                    'uploadToken' => '',
                    'uploadInstructions' => [
                        ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/p1', 'firstByte' => 0, 'lastByte' => 5],
                        ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/p2', 'firstByte' => 6, 'lastByte' => 9],
                    ],
                ]]),
                str_contains($r->url(), 'dms-uploads/p1') => Http::response('', 200, ['ETag' => '"e1"']),
                str_contains($r->url(), 'dms-uploads/p2') => Http::response('', 200, ['ETag' => '"e2"']),
                str_contains($r->url(), 'videos?action=finalizeUpload') => Http::response('', 200),
                str_contains($r->url(), '/rest/videos/') => Http::response(['status' => 'AVAILABLE']),
                str_ends_with($r->url(), '/rest/posts') => Http::response('', 201, ['x-restli-id' => 'urn:li:ugcPost:5']),
            };
        });

        $result = $this->provider()->publish(new OAuthTokens('T'), self::PERSON, new PublishPayload(
            body: "Nuevo video\nCon detalles",
            mediaTypes: ['video'],
            mediaFiles: [$this->file('video/mp4', $video)],
        ), self::CREDENTIALS);

        $this->assertSame('urn:li:ugcPost:5', $result->remoteId);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dms-uploads/p1') && $r->body() === 'vvvvvv' && ! $r->hasHeader('Authorization'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dms-uploads/p2') && $r->body() === 'vvvv');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'finalizeUpload') && $r['finalizeUploadRequest']['uploadedPartIds'] === ['e1', 'e2']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/posts')
            && $r['content']['media']['id'] === 'urn:li:video:V1'
            && $r['content']['media']['title'] === 'Nuevo video');
    }

    public function test_no_mezcla_video_con_imagenes_ni_repite_una_publicacion_ya_creada(): void
    {
        $this->expectException(SocialProviderException::class);
        try {
            $this->provider()->publish(new OAuthTokens('T'), self::PERSON, new PublishPayload(
                body: 'x',
                mediaFiles: [$this->file('video/mp4', 'v'), $this->file('image/png', 'i')],
            ), self::CREDENTIALS);
        } finally {
            Http::assertNothingSent();

            // Reintento tras crear la publicación: se devuelve la guardada sin llamar a LinkedIn.
            $checkpoint = new PublishCheckpoint(['linkedin.post' => 'urn:li:share:1']);
            $result = $this->provider()->publish(new OAuthTokens('T'), self::PERSON, new PublishPayload('x', checkpoint: $checkpoint), self::CREDENTIALS);
            $this->assertSame('urn:li:share:1', $result->remoteId);
            Http::assertNothingSent();
        }
    }

    public function test_token_rechazado_marca_la_conexion_como_expirada(): void
    {
        Http::fake(['*' => Http::response(['status' => 401, 'serviceErrorCode' => 65600, 'message' => 'Invalid access token'], 401)]);

        $this->expectException(SocialTokenExpiredException::class);
        $this->provider()->publish(new OAuthTokens('T'), self::PERSON, new PublishPayload('Hola'), self::CREDENTIALS);
    }

    public function test_metricas_de_pagina_y_perfil_sin_permiso_de_analitica(): void
    {
        Http::fake([
            'api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 1500]),
            'api.linkedin.com/rest/organizationalEntityShareStatistics*' => Http::response(['elements' => [[
                'totalShareStatistics' => ['impressionCount' => 900, 'uniqueImpressionsCount' => 700, 'likeCount' => 10, 'commentCount' => 2, 'shareCount' => 1, 'clickCount' => 5],
            ]]]),
            'api.linkedin.com/rest/memberCreatorPostAnalytics*' => Http::response(['message' => 'Not enough permissions'], 403),
        ]);

        $account = $this->provider()->fetchAccountMetrics(new OAuthTokens('T'), 'urn:li:organization:42', self::CREDENTIALS);
        $this->assertSame(1500, $account->followers);
        $this->assertSame(900, $account->impressions);
        $this->assertSame(18, $account->engagement);

        $post = $this->provider()->fetchPostMetrics(new OAuthTokens('T', scopes: ['w_member_social']), 'urn:li:share:1', self::CREDENTIALS);
        $this->assertSame(0, $post->impressions);
    }

    public function test_comentarios_de_pagina_y_respuesta_en_su_nombre(): void
    {
        Http::fake([
            'api.linkedin.com/rest/posts?*' => Http::response(['elements' => [['id' => 'urn:li:share:10']]]),
            'api.linkedin.com/rest/socialActions/urn%3Ali%3Ashare%3A10/comments*' => Http::response(['elements' => [
                ['actor' => 'urn:li:person:zz', 'commentUrn' => 'urn:li:comment:(urn:li:share:10,55)', 'created' => ['time' => 1767225600000], 'message' => ['text' => '¿Precio?']],
                ['actor' => 'urn:li:organization:42', 'commentUrn' => 'urn:li:comment:(urn:li:share:10,56)', 'message' => ['text' => 'Nuestra respuesta']],
            ]]),
            'api.linkedin.com/rest/socialActions/urn%3Ali%3Acomment*' => Http::response(['commentUrn' => 'urn:li:comment:(urn:li:share:10,57)'], 201),
        ]);
        $tokens = new OAuthTokens('T', scopes: ['r_organization_social_feed', 'w_organization_social_feed']);

        $threads = $this->provider()->fetchConversations($tokens, 'urn:li:organization:42', self::CREDENTIALS);
        $this->assertCount(1, $threads);
        $this->assertSame('urn:li:organization:42|urn:li:comment:(urn:li:share:10,55)', $threads[0]->externalId);
        $this->assertSame('¿Precio?', $threads[0]->messages[0]->body);

        $reply = $this->provider()->replyToConversation($tokens, $threads[0]->externalId, 'Te escribimos', self::CREDENTIALS);
        $this->assertSame('urn:li:comment:(urn:li:share:10,57)', $reply->externalId);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'socialActions/urn%3Ali%3Acomment')
            && $r['actor'] === 'urn:li:organization:42' && $r['object'] === 'urn:li:share:10'
            && $r['parentComment'] === 'urn:li:comment:(urn:li:share:10,55)');

        // En perfiles no se leen comentarios (permiso restringido por LinkedIn).
        $this->assertSame([], $this->provider()->fetchConversations($tokens, self::PERSON, self::CREDENTIALS));
    }

    public function test_probar_conexion_distingue_cliente_invalido_de_codigo_invalido(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'invalid_request', 'error_description' => 'authorization code not found'], 400)
            ->push(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401)]);

        $this->provider()->verifyCredentials(self::CREDENTIALS); // credenciales válidas: no lanza

        $this->expectException(SocialProviderException::class);
        $this->provider()->verifyCredentials(self::CREDENTIALS);
    }
}
