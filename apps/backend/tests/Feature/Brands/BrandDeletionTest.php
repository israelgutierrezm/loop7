<?php

declare(strict_types=1);

namespace Tests\Feature\Brands;

use App\Modules\Automations\Models\Automation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: ContentItem, 1: PublicationTarget, 2: SocialConnection, 3: Automation}
     */
    private function brandWithActivity(Organization $org, Brand $brand): array
    {
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo', 'access_token' => 'TOKEN',
        ]);
        $destination = SocialConnectionDestination::query()->create([
            'organization_id' => $org->id, 'social_connection_id' => $connection->id,
            'external_id' => 'd-' . $brand->id, 'name' => 'Página', 'type' => 'page', 'access_token' => 'PAGE',
        ]);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Programado',
            'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
        ]);
        $variant = $content->variants()->create(['organization_id' => $org->id, 'provider' => 'fake', 'body' => 'x']);
        $target = PublicationTarget::query()->create([
            'organization_id' => $org->id, 'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id, 'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
        ]);
        $automation = Automation::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Regla', 'is_enabled' => true,
            'trigger' => 'content.published', 'conditions' => [], 'actions' => [['type' => 'notify', 'config' => ['message' => 'x']]],
        ]);

        return [$content, $target, $connection, $automation];
    }

    public function test_eliminar_marca_cancela_lo_programado_y_revoca_sus_cuentas(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $other = Brand::factory()->create(['organization_id' => $org->id]);
        [$content, $target, $connection, $automation] = $this->brandWithActivity($org, $brand);
        [$otherContent, $otherTarget, $otherConnection, $otherAutomation] = $this->brandWithActivity($org, $other);

        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/brands/{$brand->public_id}")->assertOk();

        $this->assertSame('cancelled', $target->fresh()->status->value);
        $this->assertSame('cancelled', ContentItem::query()->withoutGlobalScopes()->find($content->id)->status->value);
        $revoked = SocialConnection::query()->withoutGlobalScopes()->withTrashed()->find($connection->id);
        $this->assertSame('revoked', $revoked->status->value);
        $this->assertNull($revoked->access_token);
        $this->assertTrue($revoked->trashed());
        $this->assertFalse($automation->fresh()->is_enabled);

        // La otra marca sigue intacta.
        $this->assertSame('scheduled', $otherTarget->fresh()->status->value);
        $this->assertSame('scheduled', $otherContent->fresh()->status->value);
        $this->assertSame('connected', $otherConnection->fresh()->status->value);
        $this->assertTrue($otherAutomation->fresh()->is_enabled);
    }
}
