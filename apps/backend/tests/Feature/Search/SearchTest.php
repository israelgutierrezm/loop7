<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Content\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_busca_contenido_marcas_campanas_y_miembros(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Otoño']);
        ContentItem::query()->create(['organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Lanzamiento de otoño', 'status' => 'draft']);
        ContentItem::query()->create(['organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Otro', 'body' => 'Promo de otoño', 'status' => 'draft']);
        Campaign::query()->create(['organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Campaña otoño', 'status' => 'active']);
        $this->addMember($org, OrganizationRole::VIEWER->value, userAttributes: ['name' => 'Otoniel Pérez']);

        $response = $this->actingInOrganization($owner, $org)->getJson('/api/v1/search?q=' . urlencode('otoñ'))->assertOk();

        $this->assertCount(2, $response->json('data.content'));
        $this->assertSame('Café Otoño', $response->json('data.brands.0.title'));
        $this->assertSame('Campaña otoño', $response->json('data.campaigns.0.title'));
        $response->assertJsonPath('data.content.0.url', fn (string $url) => str_starts_with($url, '/app/content/'));

        $members = $this->actingInOrganization($owner, $org)->getJson('/api/v1/search?q=otoniel')->json('data.members');
        $this->assertSame('Otoniel Pérez', $members[0]['title']);
    }

    public function test_respeta_permisos_acceso_por_marca_y_organizacion(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        [$otherOwner, $otherOrg] = $this->createOwnerWithOrganization([], 'Otra');
        $mine = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Marca visible']);
        $hidden = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Marca oculta']);
        $foreign = Brand::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Marca ajena']);
        foreach ([$mine, $hidden, $foreign] as $b) {
            ContentItem::query()->create(['organization_id' => $b->organization_id, 'brand_id' => $b->id, 'title' => "Post {$b->name}", 'status' => 'draft']);
        }

        // Limitado a una marca: sólo ve lo de esa marca.
        $limited = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value, allBrandsAccess: false);
        $mine->grantAccessTo($limited->id);
        $data = $this->actingInOrganization($limited, $org)->getJson('/api/v1/search?q=Marca')->json('data');
        $this->assertSame(['Marca visible'], array_column($data['brands'], 'title'));
        $this->assertSame(['Post Marca visible'], array_column($data['content'], 'title'));

        // Sin permiso de contenido (BILLING): no hay sección de contenido.
        $billing = $this->addMember($org, OrganizationRole::BILLING->value);
        $this->assertArrayNotHasKey('content', $this->actingInOrganization($billing, $org)->getJson('/api/v1/search?q=Marca')->json('data'));

        // Otra organización nunca aparece.
        $titles = array_column($this->actingInOrganization($owner, $org)->getJson('/api/v1/search?q=ajena')->json('data.brands'), 'title');
        $this->assertSame([], $titles);
        $this->assertSame(['Marca ajena'], array_column($this->actingInOrganization($otherOwner, $otherOrg)->getJson('/api/v1/search?q=ajena')->json('data.brands'), 'title'));
    }

    public function test_los_comodines_se_buscan_literalmente_y_hay_minimo(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Normal']);
        ContentItem::query()->create(['organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Sin porcentaje', 'status' => 'draft']);

        $this->assertSame([], $this->actingInOrganization($owner, $org)->getJson('/api/v1/search?q=' . urlencode('%%'))->json('data.content'));
        $this->actingInOrganization($owner, $org)->getJson('/api/v1/search?q=a')->assertStatus(422);
    }
}
