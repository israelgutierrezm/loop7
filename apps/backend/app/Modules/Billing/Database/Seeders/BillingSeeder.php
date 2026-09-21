<?php

declare(strict_types=1);

namespace App\Modules\Billing\Database\Seeders;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Entitlements\PlanCatalog;
use App\Modules\Billing\Models\AddOn;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanEntitlement;
use App\Modules\Billing\Models\PlanPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BillingSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo de entitlements.
        foreach (Entitlement::definitions() as $key => $def) {
            DB::table('entitlements')->updateOrInsert(
                ['key' => $key],
                ['type' => $def['type'], 'label' => $def['label'], 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // Planes, precios y entitlements por plan.
        foreach (PlanCatalog::plans() as $key => $data) {
            $plan = Plan::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'is_active' => true,
                    'is_public' => $data['is_public'],
                    'sort_order' => $data['sort'],
                    'trial_days' => PlanCatalog::TRIAL_DAYS,
                ],
            );

            foreach ($data['prices'] as $interval => $amount) {
                PlanPrice::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'interval' => $interval, 'currency' => PlanCatalog::CURRENCY],
                    ['amount_cents' => $amount, 'is_active' => true],
                );
            }

            foreach ($data['entitlements'] as $entKey => $value) {
                PlanEntitlement::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'entitlement_key' => $entKey],
                    ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value],
                );
            }
        }

        // Add-ons de ejemplo.
        $addOns = [
            ['key' => 'extra-brand', 'name' => 'Marca adicional', 'entitlement_key' => Entitlement::BRANDS_MAX, 'quantity_per_unit' => 1, 'price_cents' => 900],
            ['key' => 'extra-member', 'name' => 'Miembro adicional', 'entitlement_key' => Entitlement::TEAM_MEMBERS_MAX, 'quantity_per_unit' => 1, 'price_cents' => 600],
            ['key' => 'ai-credits-1000', 'name' => '1.000 créditos IA', 'entitlement_key' => Entitlement::AI_CREDITS_MONTH, 'quantity_per_unit' => 1000, 'price_cents' => 1500],
        ];
        foreach ($addOns as $addOn) {
            AddOn::query()->updateOrCreate(
                ['key' => $addOn['key']],
                array_merge($addOn, ['currency' => PlanCatalog::CURRENCY, 'is_active' => true]),
            );
        }
    }
}
