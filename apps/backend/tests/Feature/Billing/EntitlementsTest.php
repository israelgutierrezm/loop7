<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Models\AddOn;
use App\Modules\Billing\Models\OrganizationAddOn;
use App\Modules\Billing\Services\EntitlementsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_el_trial_otorga_los_entitlements_del_plan_growth(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $entitlements = app(EntitlementsService::class);

        $this->assertSame(3, $entitlements->limit($org, Entitlement::BRANDS_MAX));
        $this->assertTrue($entitlements->allows($org, Entitlement::FEATURE_INBOX));
        $this->assertFalse($entitlements->allows($org, Entitlement::FEATURE_WHITE_LABEL));
    }

    public function test_el_plan_enterprise_es_ilimitado(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'enterprise');
        $entitlements = app(EntitlementsService::class);
        $entitlements->flush();

        $this->assertTrue($entitlements->isUnlimited($org, Entitlement::BRANDS_MAX));
        $this->assertTrue($entitlements->withinLimit($org, Entitlement::BRANDS_MAX, 9999));
    }

    public function test_un_addon_incrementa_el_limite(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // brands.max = 1

        $entitlements = app(EntitlementsService::class);
        $entitlements->flush();
        $this->assertSame(1, $entitlements->limit($org, Entitlement::BRANDS_MAX));

        $addOn = AddOn::query()->where('key', 'extra-brand')->firstOrFail();
        OrganizationAddOn::query()->create([
            'organization_id' => $org->id,
            'add_on_id' => $addOn->id,
            'quantity' => 2,
        ]);

        $entitlements->flush();
        $this->assertSame(3, $entitlements->limit($org, Entitlement::BRANDS_MAX));
    }
}
