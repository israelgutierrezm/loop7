<?php

declare(strict_types=1);

namespace Tests\Feature\Competitors;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Analytics\Models\AccountMetricSnapshot;
use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Competitors\Models\Competitor;
use App\Modules\Competitors\Models\CompetitorAccount;
use App\Modules\Competitors\Models\CompetitorPost;
use App\Modules\Competitors\Models\CompetitorSnapshot;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Análisis de competidores (docs/05): alta con búsqueda en la red, límites del
 * plan, fuentes oficiales (Instagram, Facebook, Threads), comparación con la
 * marca, sincronización diaria y aislamiento.
 */
class CompetitorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Brand}
     */
    private function scenario(string $plan = 'professional'): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, $plan);
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Aurora']);

        return [$owner, $org, $brand];
    }

    private function url(Brand $brand, string $path = ''): string
    {
        return "/api/v1/brands/{$brand->public_id}/competitors{$path}";
    }

    private function connect(Organization $org, Brand $brand, string $provider, array $scopes = []): SocialConnectionDestination
    {
        SocialProvider::query()->where('key', $provider)->update(['is_enabled' => true]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => $provider,
            'status' => 'connected', 'external_account_name' => '@marca', 'access_token' => 'USER_TOKEN', 'scopes' => $scopes,
        ]);

        return SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'VIEWER1', 'name' => '@marca', 'type' => 'account', 'access_token' => 'PAGE_TOKEN',
        ]);
    }

    public function test_seguir_un_competidor_con_la_red_de_prueba(): void
    {
        [$owner, $org, $brand] = $this->scenario();

        $response = $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Café Rival', 'accounts' => [['provider' => 'fake', 'handle' => 'https://example.com/@CafeRival']]])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Café Rival')
            ->assertJsonPath('data.accounts.0.handle', 'caferival')
            ->assertJsonPath('data.accounts.0.status', 'active');

        $this->assertGreaterThan(0, $response->json('data.accounts.0.followers'));
        $account = CompetitorAccount::query()->sole();
        $this->assertSame(1, CompetitorSnapshot::query()->where('competitor_account_id', $account->id)->count());
        $this->assertSame(8, CompetitorPost::query()->where('competitor_account_id', $account->id)->count());
        $this->assertSame(['fake:caferival'], AuditLog::query()->where('action', 'competitor.created')->sole()->properties['accounts']);

        $this->actingInOrganization($owner, $org)
            ->getJson($this->url($brand))
            ->assertOk()
            ->assertJsonPath('data.competitors.0.accounts.0.handle', 'caferival')
            ->assertJsonPath('data.usage', ['used' => 1, 'limit' => 10]);
    }

    public function test_errores_por_cuenta_y_limite_del_plan(): void
    {
        [$owner, $org, $brand] = $this->scenario('growth'); // 3 cuentas
        $client = $this->actingInOrganization($owner, $org);

        $client->postJson($this->url($brand), ['name' => 'Rival', 'accounts' => [
            ['provider' => 'fake', 'handle' => '@@ no vale'],
            ['provider' => 'fake', 'handle' => 'noexiste'],
            ['provider' => 'tiktok', 'handle' => 'alguien'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['accounts.0.handle', 'accounts.1.handle', 'accounts.2.provider']);
        $this->assertSame(0, Competitor::query()->count(), 'nada a medias');

        $client->postJson($this->url($brand), ['name' => 'Uno', 'accounts' => [
            ['provider' => 'fake', 'handle' => 'uno'], ['provider' => 'fake', 'handle' => 'dos'],
        ]])->assertCreated();
        $id = Competitor::query()->sole()->public_id;
        $client->postJson($this->url($brand, "/{$id}/accounts"), ['provider' => 'fake', 'handle' => 'dos'])
            ->assertStatus(422)->assertJsonPath('errors.handle.0', 'Ya sigues esa cuenta.');
        $client->postJson($this->url($brand, "/{$id}/accounts"), ['provider' => 'fake', 'handle' => 'tres'])->assertCreated();

        $client->postJson($this->url($brand), ['name' => 'Otro', 'accounts' => [['provider' => 'fake', 'handle' => 'cuatro']]])
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'competitor_accounts.max');

        $this->setOrganizationPlan($org, 'starter'); // sin competencia
        $client->postJson($this->url($brand), ['name' => 'Otro', 'accounts' => [['provider' => 'fake', 'handle' => 'cuatro']]])
            ->assertStatus(402)
            ->assertJsonPath('message', 'Tu plan no incluye el análisis de competidores.');
    }

    public function test_instagram_con_business_discovery(): void
    {
        [$owner, $org, $brand] = $this->scenario();

        // Sin cuenta de Instagram conectada se explica cómo activarlo.
        SocialProvider::query()->where('key', 'instagram')->update(['is_enabled' => true]);
        $sources = collect($this->actingInOrganization($owner, $org)->getJson($this->url($brand))->json('data.sources'))->keyBy('key');
        $this->assertFalse($sources['instagram']['available']);
        $this->assertStringContainsString('Conecta una cuenta de Instagram', $sources['instagram']['reason']);

        $this->connect($org, $brand, 'instagram');
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;
            if (str_contains((string) $request['fields'], 'username(noexiste)')) {
                return Http::response(['error' => ['message' => 'Invalid user id', 'code' => 110, 'error_subcode' => 2207013]], 400);
            }
            // Versión de la API sin view_count: se repite sin ese campo.
            if (str_contains((string) $request['fields'], 'view_count')) {
                return Http::response(['error' => ['message' => '(#100) Tried accessing nonexisting field (view_count)', 'code' => 100]], 400);
            }

            return Http::response(['business_discovery' => [
                'id' => '17841400000', 'username' => 'bluebottle', 'name' => 'Blue Bottle Coffee',
                'followers_count' => 412000, 'media_count' => 1500,
                'media' => ['data' => [
                    ['id' => 'M1', 'like_count' => 1200, 'comments_count' => 80, 'timestamp' => now()->subDays(2)->toIso8601String(), 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'permalink' => 'https://www.instagram.com/reel/M1/'],
                    ['id' => 'M2', 'comments_count' => 15, 'timestamp' => now()->subDays(5)->toIso8601String(), 'media_type' => 'CAROUSEL_ALBUM', 'caption' => 'Nuevo origen'],
                ]],
            ]]);
        });

        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Blue Bottle', 'accounts' => [
                ['provider' => 'instagram', 'handle' => 'https://www.instagram.com/BlueBottle/'],
            ]])
            ->assertCreated()
            ->assertJsonPath('data.accounts.0.followers', 412000)
            ->assertJsonPath('data.accounts.0.profile_url', 'https://www.instagram.com/bluebottle/');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/VIEWER1?')
            && str_contains((string) $r['fields'], 'business_discovery.username(bluebottle)')
            && $r['access_token'] === 'PAGE_TOKEN');
        $posts = CompetitorPost::query()->orderBy('external_id')->get();
        $this->assertSame(['reel', 'carousel'], $posts->pluck('type')->all());
        $this->assertSame(1280, $posts[0]->engagement());
        $this->assertNull($posts[1]->likes, 'si la cuenta oculta los «me gusta», no se inventan');

        $errors = $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Nadie', 'accounts' => [['provider' => 'instagram', 'handle' => 'noexiste']]])
            ->assertStatus(422)
            ->json('errors');
        $this->assertSame('No encontramos @noexiste en Instagram o no es una cuenta profesional (empresa o creador).', $errors['accounts.0.handle'][0]);
    }

    public function test_facebook_y_threads(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $this->connect($org, $brand, 'facebook');
        $this->connect($org, $brand, 'threads');

        Http::fake([
            'graph.facebook.com/*/sinpermiso*' => Http::response(['error' => ['message' => 'Requires Page Public Metadata Access', 'code' => 10]], 403),
            'graph.facebook.com/*' => Http::response(['id' => '123', 'name' => 'Rival', 'username' => 'Rival', 'fan_count' => 900, 'followers_count' => 1000, 'link' => 'https://www.facebook.com/Rival']),
            'graph.threads.net/*' => Http::response(['username' => 'rival', 'follower_count' => 5300, 'likes_count' => 400, 'quotes_count' => 5, 'reposts_count' => 30, 'views_count' => 12000]),
        ]);

        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Rival', 'accounts' => [['provider' => 'facebook', 'handle' => 'facebook.com/Rival']]])
            ->assertCreated()
            ->assertJsonPath('data.accounts.0.followers', 1000);
        $errors = $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Sin permiso', 'accounts' => [['provider' => 'facebook', 'handle' => 'sinpermiso']]])
            ->assertStatus(422)
            ->json('errors');
        $this->assertSame('La app de Meta necesita la función «Page Public Metadata Access» (aprobada en la revisión de Meta) para leer páginas que no administras.', $errors['accounts.0.handle'][0]);

        // Threads exige el permiso de Profile Discovery en la conexión.
        $sources = collect($this->actingInOrganization($owner, $org)->getJson($this->url($brand))->json('data.sources'))->keyBy('key');
        $this->assertFalse($sources['threads']['available']);
        SocialConnection::query()->where('provider', 'threads')->update(['scopes' => json_encode(['threads_basic', 'threads_profile_discovery'])]);

        $competitor = Competitor::query()->sole();
        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand, "/{$competitor->public_id}/accounts"), ['provider' => 'threads', 'handle' => '@rival'])
            ->assertCreated();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.threads.net/v1.0/profile_lookup') && $r['username'] === 'rival');
        $snapshot = CompetitorSnapshot::query()->latest('id')->first();
        $this->assertSame(5300, $snapshot?->followers);
        $this->assertSame(['likes' => 400, 'quotes' => 5, 'reposts' => 30, 'views' => 12000], $snapshot?->weekly);
    }

    public function test_comparacion_con_la_marca(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $destination = $this->connect($org, $brand, 'fake');

        // Cuenta propia: seguidores de 1000 a 1100 y dos publicaciones medidas.
        foreach ([29 => 1000, 15 => 1050, 0 => 1100] as $daysAgo => $followers) {
            AccountMetricSnapshot::query()->create([
                'organization_id' => $org->id, 'brand_id' => $brand->id, 'social_connection_destination_id' => $destination->id,
                'provider' => 'fake', 'date' => Carbon::today()->subDays($daysAgo)->toDateString(), 'followers' => $followers,
            ]);
        }
        foreach ([[40, 10], [20, 10]] as $i => [$likes, $comments]) {
            $content = ContentItem::query()->create(['organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => "P{$i}", 'status' => 'published']);
            $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => 'fake', 'body' => 'x']);
            $target = PublicationTarget::query()->create([
                'organization_id' => $org->id, 'post_variant_id' => $variant->id, 'social_connection_destination_id' => $destination->id,
                'status' => 'published', 'published_at' => now()->subDays(3 + $i),
            ]);
            PostMetricSnapshot::query()->create([
                'organization_id' => $org->id, 'brand_id' => $brand->id, 'publication_target_id' => $target->id, 'provider' => 'fake',
                'date' => Carbon::today()->toDateString(), 'likes' => $likes, 'comments' => $comments, 'engagement' => $likes + $comments,
            ]);
        }

        // Competidor: la red de prueba guarda la foto de hoy; se añade una de hace 20 días.
        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Café Rival', 'accounts' => [['provider' => 'fake', 'handle' => 'rival']]])
            ->assertCreated();
        $account = CompetitorAccount::query()->sole();
        $today = (int) CompetitorSnapshot::query()->sole()->followers;
        CompetitorSnapshot::query()->create([
            'organization_id' => $org->id, 'competitor_account_id' => $account->id,
            'date' => Carbon::today()->subDays(20), 'followers' => $today - 500,
        ]);

        $data = $this->actingInOrganization($owner, $org)
            ->getJson($this->url($brand, '/benchmark?days=30&provider=fake'))
            ->assertOk()
            ->json('data');

        [$own, $rival] = $data['rows'];
        // (50 + 30) / 2 publicaciones = 40 de media; 40 / 1100 seguidores = 3,64 %.
        $this->assertEquals(['own', 1100, 100, 10, 2, 40, 3.64], [$own['kind'], $own['followers'], $own['followers_change'], $own['followers_change_pct'], $own['posts'], $own['avg_engagement'], $own['engagement_rate']]);
        $this->assertSame('competitor', $rival['kind']);
        $this->assertSame('@rival', $rival['account']);
        $this->assertSame(500, $rival['followers_change']);
        $this->assertSame(CompetitorPost::query()->where('published_at', '>=', now()->subDays(29)->startOfDay())->count(), $rival['posts']);
        $this->assertArrayNotHasKey('history', $rival);

        $this->assertCount(30, $data['series']['dates']);
        $this->assertSame(1100, $data['series']['lines'][0]['values'][29]);
        $this->assertSame($today - 500, $data['series']['lines'][1]['values'][9]);
        $engagements = array_column($data['top_posts'], 'engagement');
        $this->assertSame($engagements, collect($engagements)->sortDesc()->values()->all(), 'de más a menos interacción');

        $this->actingInOrganization($owner, $org)->getJson($this->url($brand, '/benchmark?days=12'))->assertStatus(422);
    }

    public function test_sincronizacion_diaria_errores_y_limpieza(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $this->connect($org, $brand, 'instagram');
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['business_discovery' => ['id' => '1', 'username' => 'rival', 'followers_count' => 100, 'media' => ['data' => []]]])
            ->push(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400)
            ->push(['business_discovery' => ['id' => '1', 'username' => 'rival', 'followers_count' => 140, 'media' => ['data' => []]]]),
        ]);
        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Rival', 'accounts' => [['provider' => 'instagram', 'handle' => 'rival']]])
            ->assertCreated();
        $account = CompetitorAccount::query()->sole();

        // Hoy ya tiene foto: no se vuelve a consultar.
        $this->artisan('competitors:sync-due')->assertSuccessful();
        Http::assertSentCount(1);

        // Mañana: el token falla y queda en la cuenta; al día siguiente se recupera.
        $this->travel(1)->days();
        $this->artisan('competitors:sync-due')->assertSuccessful();
        $account->refresh();
        $this->assertSame('error', $account->status);
        $this->assertSame(1, $account->failures);
        $this->assertStringContainsString('perdió el acceso', (string) $account->last_error);

        $this->travel(1)->days();
        $this->artisan('competitors:sync-due')->assertSuccessful();
        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame(0, $account->failures);
        $this->assertSame(140, CompetitorSnapshot::query()->whereDate('date', Carbon::today())->value('followers'));

        // Limpieza de lo antiguo.
        CompetitorSnapshot::query()->create(['organization_id' => $org->id, 'competitor_account_id' => $account->id, 'date' => Carbon::today()->subDays(401), 'followers' => 1]);
        CompetitorPost::query()->create(['organization_id' => $org->id, 'competitor_account_id' => $account->id, 'external_id' => 'viejo', 'published_at' => now()->subDays(121)]);
        $this->travel(1)->days();
        $this->setOrganizationPlan($org, 'starter'); // sin competencia: no se consulta
        $this->artisan('competitors:sync-due')->assertSuccessful();
        Http::assertSentCount(3);
        $this->assertSame(0, CompetitorSnapshot::query()->whereDate('date', '<', Carbon::today()->subDays(400))->count());
        $this->assertFalse(CompetitorPost::query()->where('external_id', 'viejo')->exists());
    }

    public function test_permisos_y_aislamiento(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Rival', 'accounts' => [['provider' => 'fake', 'handle' => 'rival']]])
            ->assertCreated();
        $id = Competitor::query()->sole()->public_id;

        // Quien sólo ve la analítica no gestiona competidores.
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);
        $this->actingInOrganization($viewer, $org)->getJson($this->url($brand))->assertOk();
        $this->actingInOrganization($viewer, $org)->postJson($this->url($brand), ['name' => 'X', 'accounts' => [['provider' => 'fake', 'handle' => 'x']]])->assertForbidden();
        $this->actingInOrganization($viewer, $org)->deleteJson($this->url($brand, "/{$id}"))->assertForbidden();

        // El analista sí.
        $analyst = $this->addMember($org, OrganizationRole::ANALYST->value);
        $this->actingInOrganization($analyst, $org)->patchJson($this->url($brand, "/{$id}"), ['name' => 'Rival renombrado'])->assertOk();

        // Sin acceso a la marca, nada.
        $limited = $this->addMember($org, OrganizationRole::ANALYST->value, allBrandsAccess: false);
        $this->actingInOrganization($limited, $org)->getJson($this->url($brand))->assertForbidden();

        // Otra organización no ve ni toca el competidor, ni con su id.
        [$other, $otherOrg, $otherBrand] = $this->scenario();
        $this->actingInOrganization($other, $otherOrg)->getJson($this->url($brand))->assertNotFound();
        $this->actingInOrganization($other, $otherOrg)->deleteJson($this->url($otherBrand, "/{$id}"))->assertNotFound();
        $this->assertTrue(Competitor::query()->withoutGlobalScopes()->where('public_id', $id)->exists());
    }

    public function test_quitar_cuenta_actualizar_y_eliminar(): void
    {
        [$owner, $org, $brand] = $this->scenario();
        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand), ['name' => 'Rival', 'accounts' => [['provider' => 'fake', 'handle' => 'uno'], ['provider' => 'fake', 'handle' => 'dos']]])
            ->assertCreated();
        $competitor = Competitor::query()->sole();
        $account = CompetitorAccount::query()->where('handle', 'dos')->sole();

        $this->actingInOrganization($owner, $org)
            ->deleteJson($this->url($brand, "/{$competitor->public_id}/accounts/{$account->public_id}"))
            ->assertOk()
            ->assertJsonCount(1, 'data.accounts');
        $this->assertSame(0, CompetitorPost::query()->where('competitor_account_id', $account->id)->count());

        $this->actingInOrganization($owner, $org)
            ->postJson($this->url($brand, "/{$competitor->public_id}/sync"))
            ->assertOk()
            ->assertJsonPath('message', 'Datos actualizados.');

        $this->actingInOrganization($owner, $org)->deleteJson($this->url($brand, "/{$competitor->public_id}"))->assertOk();
        $this->assertSame(0, CompetitorAccount::query()->count());
        $this->assertSame(0, CompetitorSnapshot::query()->count());
        $this->assertSame(
            ['competitor.created', 'competitor.account_removed', 'competitor.deleted'],
            AuditLog::query()->where('action', 'like', 'competitor.%')->orderBy('id')->pluck('action')->all(),
        );
    }
}
