<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Carbon;

/**
 * Uso actual de cada límite del plan (para Facturación y para hacer cumplir los
 * límites en el backend).
 */
class UsageService
{
    public function __construct(private readonly EntitlementsService $entitlements)
    {
    }

    /**
     * @return array<string, int|float>
     */
    public function current(Organization $organization): array
    {
        // Sólo se quita el scope de tenant: los registros eliminados (soft delete)
        // no cuentan para los límites.
        $bytes = (int) MediaAsset::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->sum('size_bytes');

        return [
            Entitlement::BRANDS_MAX => Brand::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->count(),
            Entitlement::SOCIAL_ACCOUNTS_MAX => $this->socialAccounts($organization),
            Entitlement::TEAM_MEMBERS_MAX => $organization->users()->count(),
            Entitlement::SCHEDULED_POSTS_MONTH => $this->scheduledPostsThisMonth($organization),
            Entitlement::STORAGE_GB => round($bytes / 1024 ** 3, 2),
            Entitlement::AI_CREDITS_MONTH => $this->entitlements->usage($organization, Entitlement::AI_CREDITS_MONTH, Carbon::now()->format('Y-m')),
        ];
    }

    /**
     * Cuentas sociales conectadas (las desconectadas no ocupan cupo).
     */
    public function socialAccounts(Organization $organization): int
    {
        return SocialConnection::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->count();
    }

    /**
     * Publicaciones (una por destino) programadas o publicadas en el mes en curso.
     */
    public function scheduledPostsThisMonth(Organization $organization): int
    {
        return PublicationTarget::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->count();
    }

    /**
     * Lanza 402 si sumar `$adding` unidades supera el límite del plan.
     */
    public function ensureWithin(Organization $organization, string $entitlement, int $current, int $adding, string $message): void
    {
        if ($adding <= 0 || $this->entitlements->isUnlimited($organization, $entitlement)) {
            return;
        }

        if ($current + $adding > $this->entitlements->limit($organization, $entitlement)) {
            throw new PlanLimitExceededException($message, $entitlement);
        }
    }
}
