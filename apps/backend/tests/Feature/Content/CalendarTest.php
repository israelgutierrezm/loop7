<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_el_calendario_devuelve_contenido_del_rango(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        ContentItem::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'title' => 'Dentro del rango',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(3),
        ]);
        ContentItem::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'title' => 'Fuera del rango',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(60),
        ]);

        $query = http_build_query([
            'from' => now()->startOfDay()->toIso8601String(),
            'to' => now()->addDays(7)->toIso8601String(),
        ]);

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/calendar?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Dentro del rango');
    }

    public function test_lo_publicado_al_momento_aparece_en_el_calendario_con_sus_redes(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo',
        ]);
        SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'd-1', 'name' => 'Página', 'type' => 'page',
        ]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Al momento', 'status' => 'approved',
        ]);
        $content->variants()->create(['organization_id' => $org->id, 'provider' => 'fake', 'body' => 'Hola']);

        $this->actingInOrganization($owner, $org)->postJson("/api/v1/content/{$content->public_id}/publish-now")->assertOk();

        $query = http_build_query(['from' => now()->subHour()->toIso8601String(), 'to' => now()->addHour()->toIso8601String()]);
        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/calendar?{$query}")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Al momento')
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.providers', ['fake']);
    }
}
