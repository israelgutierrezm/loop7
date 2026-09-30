<?php

declare(strict_types=1);

namespace Tests\Feature\SocialConnections;

use App\Models\User;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\PublishCheckpoint;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Database\Seeders\SocialProviderSeeder;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Modules\SocialConnections\Providers\GoogleBusinessProvider;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Adaptador de Google Business Profile (docs/06) contra respuestas simuladas:
 * fichas, novedades con foto y botón, borrado, métricas de la ficha y reseñas.
 */
class GoogleBusinessProviderTest extends TestCase
{
    use RefreshDatabase;

    private const CREDENTIALS = ['client_id' => 'GID.apps.googleusercontent.com', 'client_secret' => 'GSECRET'];

    private const LOCATION = 'accounts/111/locations/222';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function provider(): GoogleBusinessProvider
    {
        return new GoogleBusinessProvider();
    }

    public function test_autoriza_sin_caducidad_y_con_el_permiso_de_business(): void
    {
        $url = $this->provider()->authorizeUrl('https://app.test/cb', 'S', 'CHALLENGE', $this->provider()->defaultScopes(), self::CREDENTIALS);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('https://www.googleapis.com/auth/business.manage', $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
    }

    public function test_cada_ficha_de_cada_cuenta_es_un_destino(): void
    {
        Http::fake(function (Request $r) {
            return match (true) {
                str_starts_with($r->url(), 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts') => Http::response(['accounts' => [
                    ['name' => 'accounts/111', 'accountName' => 'Café Norte', 'type' => 'PERSONAL'],
                    ['name' => 'accounts/333', 'accountName' => 'Grupo', 'type' => 'LOCATION_GROUP'],
                ]]),
                str_contains($r->url(), '/accounts/111/locations') && ! str_contains($r->url(), 'pageToken') => Http::response([
                    'locations' => [[
                        'name' => 'locations/222', 'title' => 'Café Norte Centro',
                        'storefrontAddress' => ['addressLines' => ['Av. Juárez 10'], 'locality' => 'Monterrey'],
                        'metadata' => ['mapsUri' => 'https://maps.google.com/?cid=1'],
                    ]],
                    'nextPageToken' => 'P2',
                ]),
                str_contains($r->url(), '/accounts/111/locations') => Http::response(['locations' => [['name' => 'locations/223', 'title' => 'Café Norte Sur']]]),
                default => Http::response(['locations' => [['name' => 'locations/444', 'title' => 'Sucursal Grupo']]]),
            };
        });

        $destinations = $this->provider()->fetchDestinations(new OAuthTokens('AT'), self::CREDENTIALS);

        $this->assertSame(
            ['accounts/111/locations/222', 'accounts/111/locations/223', 'accounts/333/locations/444'],
            array_map(fn ($d) => $d->externalId, $destinations),
        );
        $this->assertSame('Café Norte Centro', $destinations[0]->name);
        $this->assertSame('Av. Juárez 10, Monterrey', $destinations[0]->metadata['address']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'readMask=name%2Ctitle%2CstorefrontAddress%2Cmetadata')
            && $r->hasHeader('Authorization', 'Bearer AT'));
    }

    public function test_publica_una_novedad_con_foto_y_boton(): void
    {
        Http::fake(['mybusiness.googleapis.com/v4/*' => Http::response([
            'name' => self::LOCATION . '/localPosts/555', 'searchUrl' => 'https://local.google.com/place?id=1&use=posts',
        ])]);
        $checkpoint = new PublishCheckpoint();

        $result = $this->provider()->publish(new OAuthTokens('AT'), self::LOCATION, new PublishPayload(
            'Nuevo tueste de temporada',
            ['https://files.test/f.jpg'],
            mediaTypes: ['image'],
            checkpoint: $checkpoint,
            options: ['call_to_action' => 'BOOK', 'cta_url' => 'https://cafe.test/reservar'],
        ), self::CREDENTIALS);

        $this->assertSame(self::LOCATION . '/localPosts/555', $result->remoteId);
        $this->assertSame('https://local.google.com/place?id=1&use=posts', $result->remoteUrl);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://mybusiness.googleapis.com/v4/' . self::LOCATION . '/localPosts'
            && $r['topicType'] === 'STANDARD'
            && $r['summary'] === 'Nuevo tueste de temporada'
            && $r['media'] === [['mediaFormat' => 'PHOTO', 'sourceUrl' => 'https://files.test/f.jpg']]
            && $r['callToAction'] === ['actionType' => 'BOOK', 'url' => 'https://cafe.test/reservar']);
        // Un reintento tras publicar no la duplica.
        $again = $this->provider()->publish(new OAuthTokens('AT'), self::LOCATION, new PublishPayload('x', checkpoint: $checkpoint), self::CREDENTIALS);
        $this->assertSame($result->remoteId, $again->remoteId);
        Http::assertSentCount(1);
    }

    public function test_el_boton_llamar_no_lleva_enlace_y_las_opciones_se_validan(): void
    {
        Http::fake(['mybusiness.googleapis.com/v4/*' => Http::response(['name' => self::LOCATION . '/localPosts/9'])]);
        $provider = $this->provider();

        $provider->publish(new OAuthTokens('AT'), self::LOCATION, new PublishPayload('Llámanos', options: ['call_to_action' => 'CALL']), self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => $r['callToAction'] === ['actionType' => 'CALL'] && ! isset($r['media']));

        $this->assertSame([], $provider->optionErrors([]));
        $this->assertSame([], $provider->optionErrors(['call_to_action' => 'CALL']));
        $this->assertNotEmpty($provider->optionErrors(['call_to_action' => 'LEARN_MORE']));
        $this->assertNotEmpty($provider->optionErrors(['call_to_action' => 'LEARN_MORE', 'cta_url' => 'javascript:alert(1)']));
        $this->assertNotEmpty($provider->optionErrors(['call_to_action' => 'DANCE', 'cta_url' => 'https://x.test']));
    }

    public function test_no_publica_video_ni_historias(): void
    {
        foreach ([
            new PublishPayload('x', ['https://files.test/v.mp4'], mediaTypes: ['video']),
            new PublishPayload('x', ['https://files.test/f.jpg'], PublishPayload::FORMAT_STORY, mediaTypes: ['image']),
        ] as $payload) {
            try {
                $this->provider()->publish(new OAuthTokens('AT'), self::LOCATION, $payload, self::CREDENTIALS);
                $this->fail('Debía rechazarse.');
            } catch (SocialProviderException) {
            }
        }
        Http::assertNothingSent();
    }

    public function test_borra_la_publicacion_y_valida_su_identificador(): void
    {
        Http::fake(['mybusiness.googleapis.com/v4/*' => Http::response([], 200)]);

        $this->provider()->deleteRemotePost(new OAuthTokens('AT'), self::LOCATION . '/localPosts/555', self::CREDENTIALS);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://mybusiness.googleapis.com/v4/' . self::LOCATION . '/localPosts/555');

        $this->expectException(SocialProviderException::class);
        $this->provider()->deleteRemotePost(new OAuthTokens('AT'), '../../otra-cosa', self::CREDENTIALS);
    }

    public function test_metricas_de_la_ficha_del_ultimo_dia_con_datos(): void
    {
        Carbon::setTestNow('2026-09-30 12:00:00');
        $point = fn (int $day, int $value) => ['date' => ['year' => 2026, 'month' => 9, 'day' => $day], 'value' => (string) $value];
        Http::fake(['businessprofileperformance.googleapis.com/*' => Http::response(['multiDailyMetricTimeSeries' => [[
            'dailyMetricTimeSeries' => [
                ['dailyMetric' => 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'timeSeries' => ['datedValues' => [$point(26, 40), $point(27, 50), ['date' => ['year' => 2026, 'month' => 9, 'day' => 29]]]]],
                ['dailyMetric' => 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'timeSeries' => ['datedValues' => [$point(27, 30)]]],
                ['dailyMetric' => 'CALL_CLICKS', 'timeSeries' => ['datedValues' => [$point(27, 4)]]],
                ['dailyMetric' => 'WEBSITE_CLICKS', 'timeSeries' => ['datedValues' => [$point(27, 6)]]],
            ],
        ]]])]);

        $metrics = $this->provider()->fetchAccountMetrics(new OAuthTokens('AT'), self::LOCATION, self::CREDENTIALS);

        // El 29 aún no tiene dato: se usa el 27 (último con valores).
        $this->assertSame(80, $metrics->impressions);
        $this->assertSame(10, $metrics->engagement);
        $this->assertSame(0, $metrics->followers);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://businessprofileperformance.googleapis.com/v1/locations/222:fetchMultiDailyMetricsTimeSeries?')
            && str_contains($r->url(), 'dailyMetrics=CALL_CLICKS&')
            && str_contains($r->url(), 'dailyRange.startDate.day=23')
            && str_contains($r->url(), 'dailyRange.endDate.day=29'));
        Carbon::setTestNow();
    }

    public function test_no_hay_metricas_por_publicacion(): void
    {
        $this->expectException(SocialProviderException::class);
        $this->expectExceptionMessage('ya no ofrece métricas por publicación');

        $this->provider()->fetchPostMetrics(new OAuthTokens('AT'), self::LOCATION . '/localPosts/1', self::CREDENTIALS);
    }

    public function test_trae_las_resenas_al_inbox_y_las_responde(): void
    {
        Http::fake(function (Request $r) {
            if ($r->method() === 'PUT') {
                return Http::response(['comment' => '¡Gracias!', 'updateTime' => '2026-09-30T10:00:00Z']);
            }

            return Http::response(['reviews' => [
                [
                    'name' => self::LOCATION . '/reviews/R1', 'reviewId' => 'R1', 'starRating' => 'FOUR',
                    'comment' => 'Muy buen café', 'reviewer' => ['displayName' => 'Laura G.', 'isAnonymous' => false],
                    'createTime' => '2026-09-28T09:00:00Z', 'updateTime' => '2026-09-28T09:00:00Z',
                    'reviewReply' => ['comment' => 'Gracias, Laura', 'updateTime' => '2026-09-29T08:00:00Z'],
                ],
                [
                    'name' => self::LOCATION . '/reviews/R2', 'reviewId' => 'R2', 'starRating' => 'TWO',
                    'reviewer' => ['displayName' => 'Nombre oculto', 'isAnonymous' => true],
                    'createTime' => '2026-09-27T09:00:00Z', 'updateTime' => '2026-09-27T09:00:00Z',
                ],
            ]]);
        });
        $provider = $this->provider();

        $threads = $provider->fetchConversations(new OAuthTokens('AT'), self::LOCATION, self::CREDENTIALS);

        $this->assertCount(2, $threads);
        $this->assertSame('review', $threads[0]->type);
        $this->assertSame(self::LOCATION . '/reviews/R1', $threads[0]->externalId);
        $this->assertSame("★★★★☆\nMuy buen café", $threads[0]->messages[0]->body);
        $this->assertSame('outbound', $threads[0]->messages[1]->direction);
        $this->assertSame('Usuario de Google', $threads[1]->participantName); // anónima
        $this->assertSame('★★☆☆☆', $threads[1]->messages[0]->body);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v4/' . self::LOCATION . '/reviews?pageSize=50&orderBy=updateTime%20desc'));

        $reply = $provider->replyToConversation(new OAuthTokens('AT'), self::LOCATION . '/reviews/R2', '¡Gracias!', self::CREDENTIALS);
        $this->assertSame('R2:reply:2026-09-30T10:00:00+00:00', $reply->externalId);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://mybusiness.googleapis.com/v4/' . self::LOCATION . '/reviews/R2/reply'
            && $r['comment'] === '¡Gracias!');
    }

    public function test_llega_deshabilitada_y_con_cliente_oauth_propio(): void
    {
        $this->seed(SocialProviderSeeder::class);
        SocialProvider::query()->where('key', 'youtube')->firstOrFail()
            ->forceFill(['credentials' => ['client_id' => 'YT-ID', 'client_secret' => 'YT-SECRET']])->save();

        // No hereda el cliente de YouTube: revocar uno al desconectar revocaría el otro.
        $this->assertArrayNotHasKey('client_id', app(SocialProviderManager::class)->credentials('google_business'));

        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $providers = collect($this->getJson('/api/v1/platform/social-providers')->assertOk()->json('data'))->keyBy('key');
        $this->assertFalse($providers['google_business']['is_enabled']);
        $this->assertNull($providers['google_business']['shares_app_with']);
        $this->assertStringContainsString('Business Profile', $providers['google_business']['setup']['redirect_hint']);
    }
}
