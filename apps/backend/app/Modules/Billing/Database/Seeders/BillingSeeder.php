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

/**
 * Catálogo inicial de billing. Es idempotente y NO destructivo: sólo crea lo que
 * falta, para que re-ejecutarlo en producción no pise los planes, precios y
 * add-ons que SUPERADMIN haya editado desde el panel.
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        // El catálogo de entitlements vive en código (Entitlement): se retiran las claves obsoletas.
        PlanEntitlement::query()->whereNotIn('entitlement_key', Entitlement::all())->delete();

        // Planes por defecto (sólo si no existen).
        foreach (PlanCatalog::plans() as $key => $data) {
            $plan = Plan::query()->firstOrCreate(
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
                PlanPrice::query()->firstOrCreate(
                    ['plan_id' => $plan->id, 'interval' => $interval, 'currency' => PlanCatalog::CURRENCY],
                    ['amount_cents' => $amount, 'is_active' => true],
                );
            }

            foreach ($data['entitlements'] as $entKey => $value) {
                PlanEntitlement::query()->firstOrCreate(
                    ['plan_id' => $plan->id, 'entitlement_key' => $entKey],
                    ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value],
                );
            }
        }

        // Add-ons de ejemplo (sólo si no existen).
        $addOns = [
            ['key' => 'extra-brand', 'name' => 'Marca adicional', 'entitlement_key' => Entitlement::BRANDS_MAX, 'quantity_per_unit' => 1, 'price_cents' => 900],
            ['key' => 'extra-member', 'name' => 'Miembro adicional', 'entitlement_key' => Entitlement::TEAM_MEMBERS_MAX, 'quantity_per_unit' => 1, 'price_cents' => 600],
            ['key' => 'ai-credits-1000', 'name' => '1.000 créditos IA', 'entitlement_key' => Entitlement::AI_CREDITS_MONTH, 'quantity_per_unit' => 1000, 'price_cents' => 1500],
        ];
        foreach ($addOns as $addOn) {
            AddOn::query()->firstOrCreate(
                ['key' => $addOn['key']],
                [...$addOn, 'currency' => PlanCatalog::CURRENCY, 'is_active' => true],
            );
        }
    }
}
