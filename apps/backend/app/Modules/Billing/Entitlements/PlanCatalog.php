<?php

declare(strict_types=1);

namespace App\Modules\Billing\Entitlements;

/**
 * Planes por defecto y sus entitlements (docs/08). Fuente para el seeder.
 * Los importes están en centavos. -1 = ilimitado.
 */
final class PlanCatalog
{
    public const CURRENCY = 'USD';

    public const TRIAL_DAYS = 14;

    /**
     * @return array<string, array{
     *   name: string, description: string, sort: int, is_public: bool,
     *   prices: array{month: int, year: int},
     *   entitlements: array<string, int|bool>
     * }>
     */
    public static function plans(): array
    {
        return [
            'starter' => [
                'name' => 'Starter',
                'description' => 'Para empezar en solitario.',
                'sort' => 1,
                'is_public' => true,
                'prices' => ['month' => 1900, 'year' => 19000],
                'entitlements' => [
                    Entitlement::BRANDS_MAX => 1,
                    Entitlement::SOCIAL_ACCOUNTS_MAX => 3,
                    Entitlement::TEAM_MEMBERS_MAX => 2,
                    Entitlement::SCHEDULED_POSTS_MONTH => 30,
                    Entitlement::STORAGE_GB => 2,
                    Entitlement::AI_CREDITS_MONTH => 100,
                    Entitlement::COMPETITORS_MAX => 0,
                    Entitlement::FEATURE_APPROVALS => false,
                    Entitlement::FEATURE_ANALYTICS_ADVANCED => false,
                    Entitlement::FEATURE_INBOX => false,
                    Entitlement::FEATURE_AUTOMATIONS => false,
                    Entitlement::FEATURE_BYOK => false,
                    Entitlement::FEATURE_API => false,
                    Entitlement::FEATURE_WHITE_LABEL => false,
                ],
            ],
            'growth' => [
                'name' => 'Growth',
                'description' => 'Para negocios en crecimiento.',
                'sort' => 2,
                'is_public' => true,
                'prices' => ['month' => 4900, 'year' => 49000],
                'entitlements' => [
                    Entitlement::BRANDS_MAX => 3,
                    Entitlement::SOCIAL_ACCOUNTS_MAX => 10,
                    Entitlement::TEAM_MEMBERS_MAX => 5,
                    Entitlement::SCHEDULED_POSTS_MONTH => 200,
                    Entitlement::STORAGE_GB => 10,
                    Entitlement::AI_CREDITS_MONTH => 500,
                    Entitlement::COMPETITORS_MAX => 3,
                    Entitlement::FEATURE_APPROVALS => true,
                    Entitlement::FEATURE_ANALYTICS_ADVANCED => true,
                    Entitlement::FEATURE_INBOX => true,
                    Entitlement::FEATURE_AUTOMATIONS => false,
                    Entitlement::FEATURE_BYOK => false,
                    Entitlement::FEATURE_API => false,
                    Entitlement::FEATURE_WHITE_LABEL => false,
                ],
            ],
            'professional' => [
                'name' => 'Professional',
                'description' => 'Para equipos de marketing.',
                'sort' => 3,
                'is_public' => true,
                'prices' => ['month' => 9900, 'year' => 99000],
                'entitlements' => [
                    Entitlement::BRANDS_MAX => 10,
                    Entitlement::SOCIAL_ACCOUNTS_MAX => 30,
                    Entitlement::TEAM_MEMBERS_MAX => 15,
                    Entitlement::SCHEDULED_POSTS_MONTH => 1000,
                    Entitlement::STORAGE_GB => 50,
                    Entitlement::AI_CREDITS_MONTH => 2000,
                    Entitlement::COMPETITORS_MAX => 10,
                    Entitlement::FEATURE_APPROVALS => true,
                    Entitlement::FEATURE_ANALYTICS_ADVANCED => true,
                    Entitlement::FEATURE_INBOX => true,
                    Entitlement::FEATURE_AUTOMATIONS => true,
                    Entitlement::FEATURE_BYOK => true,
                    Entitlement::FEATURE_API => true,
                    Entitlement::FEATURE_WHITE_LABEL => false,
                ],
            ],
            'agency' => [
                'name' => 'Agency',
                'description' => 'Para agencias con múltiples clientes.',
                'sort' => 4,
                'is_public' => true,
                'prices' => ['month' => 24900, 'year' => 249000],
                'entitlements' => [
                    Entitlement::BRANDS_MAX => 30,
                    Entitlement::SOCIAL_ACCOUNTS_MAX => 100,
                    Entitlement::TEAM_MEMBERS_MAX => 50,
                    Entitlement::SCHEDULED_POSTS_MONTH => 5000,
                    Entitlement::STORAGE_GB => 200,
                    Entitlement::AI_CREDITS_MONTH => 8000,
                    Entitlement::COMPETITORS_MAX => 30,
                    Entitlement::FEATURE_APPROVALS => true,
                    Entitlement::FEATURE_ANALYTICS_ADVANCED => true,
                    Entitlement::FEATURE_INBOX => true,
                    Entitlement::FEATURE_AUTOMATIONS => true,
                    Entitlement::FEATURE_BYOK => true,
                    Entitlement::FEATURE_API => true,
                    Entitlement::FEATURE_WHITE_LABEL => true,
                ],
            ],
            'enterprise' => [
                'name' => 'Enterprise',
                'description' => 'Necesidades a medida y controles avanzados.',
                'sort' => 5,
                'is_public' => true,
                'prices' => ['month' => 59900, 'year' => 599000],
                'entitlements' => [
                    Entitlement::BRANDS_MAX => Entitlement::UNLIMITED,
                    Entitlement::SOCIAL_ACCOUNTS_MAX => Entitlement::UNLIMITED,
                    Entitlement::TEAM_MEMBERS_MAX => Entitlement::UNLIMITED,
                    Entitlement::SCHEDULED_POSTS_MONTH => Entitlement::UNLIMITED,
                    Entitlement::STORAGE_GB => Entitlement::UNLIMITED,
                    Entitlement::AI_CREDITS_MONTH => Entitlement::UNLIMITED,
                    Entitlement::COMPETITORS_MAX => Entitlement::UNLIMITED,
                    Entitlement::FEATURE_APPROVALS => true,
                    Entitlement::FEATURE_ANALYTICS_ADVANCED => true,
                    Entitlement::FEATURE_INBOX => true,
                    Entitlement::FEATURE_AUTOMATIONS => true,
                    Entitlement::FEATURE_BYOK => true,
                    Entitlement::FEATURE_API => true,
                    Entitlement::FEATURE_WHITE_LABEL => true,
                ],
            ],
        ];
    }

    /**
     * Plan por defecto para el trial de nuevas organizaciones.
     */
    public static function defaultTrialPlan(): string
    {
        return 'growth';
    }
}
