<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function content(Organization $org, Brand $brand, string $title, string $status, array $extra = []): ContentItem
    {
        $item = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => $title, 'status' => $status, ...$extra,
        ]);
        $item->variants()->create(['organization_id' => $org->id, 'provider' => 'instagram', 'body' => 'x']);

        return $item;
    }

    public function test_resumen_con_proximas_publicaciones_pendientes_y_puesta_en_marcha(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Cafetería']);
        $this->content($org, $brand, 'Mañana', 'scheduled', ['scheduled_at' => now()->addDay()]);
        $this->content($org, $brand, 'Pasado', 'scheduled', ['scheduled_at' => now()->addDays(2)]);
        $this->content($org, $brand, 'En revisión', 'in_review');
        $this->content($org, $brand, 'Falló', 'failed');
        SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'expired', 'external_account_name' => 'Demo',
        ]);

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.onboarding.has_brand', true)
            ->assertJsonPath('data.onboarding.has_connection', true)
            ->assertJsonPath('data.onboarding.has_published', false)
            ->assertJsonPath('data.content.in_review', 1)
            ->assertJsonPath('data.content.scheduled_next_7_days', 2)
            ->assertJsonPath('data.content.upcoming.0.title', 'Mañana')
            ->assertJsonPath('data.content.upcoming.0.brand', 'Cafetería')
            ->assertJsonPath('data.content.upcoming.0.providers', ['instagram'])
            ->assertJsonPath('data.content.attention.0.title', 'Falló')
            ->assertJsonPath('data.social.needs_attention', 1)
            ->assertJsonPath('data.team.members', 1);
    }

    public function test_solo_cuenta_las_marcas_accesibles_y_los_bloques_permitidos(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $mine = Brand::factory()->create(['organization_id' => $org->id]);
        $other = Brand::factory()->create(['organization_id' => $org->id]);
        $this->content($org, $mine, 'Mía', 'in_review');
        $this->content($org, $other, 'Ajena', 'in_review');
        $this->content($org, $other, 'Ajena programada', 'scheduled', ['scheduled_at' => now()->addDay()]);

        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value, allBrandsAccess: false);
        $mine->grantAccessTo($creator->id);

        $this->actingInOrganization($creator, $org)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.content.in_review', 1)
            ->assertJsonPath('data.content.upcoming', []);

        // BILLING no ve contenido ni equipo, pero sí el uso del plan.
        $billing = $this->addMember($org, OrganizationRole::BILLING->value);
        $data = $this->actingInOrganization($billing, $org)->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertArrayNotHasKey('content', $data);
        $this->assertArrayNotHasKey('team', $data);
        $this->assertArrayNotHasKey('social', $data);
        $this->assertArrayHasKey('usage', $data);
    }
}
