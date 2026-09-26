<?php

declare(strict_types=1);

namespace App\Modules\Billing\Entitlements;

/**
 * Catálogo de entitlements (límites y features de plan) — docs/08.
 *
 * Tipos:
 *  - 'limit': entero; -1 significa ilimitado.
 *  - 'bool' : feature on/off.
 */
final class Entitlement
{
    // Límites
    public const BRANDS_MAX = 'brands.max';
    public const SOCIAL_ACCOUNTS_MAX = 'social_accounts.max';
    public const TEAM_MEMBERS_MAX = 'team_members.max';
    public const SCHEDULED_POSTS_MONTH = 'scheduled_posts.month';
    public const STORAGE_GB = 'storage.gb';
    public const AI_CREDITS_MONTH = 'ai_credits.month';
    public const KNOWLEDGE_DOCUMENTS_MAX = 'knowledge_documents.max';

    // Features
    public const FEATURE_APPROVALS = 'feature.approvals';
    public const FEATURE_ANALYTICS_ADVANCED = 'feature.analytics_advanced';
    public const FEATURE_INBOX = 'feature.inbox';
    public const FEATURE_AUTOMATIONS = 'feature.automations';
    public const FEATURE_BYOK = 'feature.byok';
    public const FEATURE_API = 'feature.api';
    public const FEATURE_WHITE_LABEL = 'feature.white_label';
    public const FEATURE_CUSTOM_ROLES = 'feature.custom_roles';

    public const TYPE_LIMIT = 'limit';
    public const TYPE_BOOL = 'bool';

    public const UNLIMITED = -1;

    /**
     * Definición de cada entitlement: tipo y etiqueta.
     *
     * @return array<string, array{type: string, label: string}>
     */
    public static function definitions(): array
    {
        return [
            self::BRANDS_MAX => ['type' => self::TYPE_LIMIT, 'label' => 'Marcas'],
            self::SOCIAL_ACCOUNTS_MAX => ['type' => self::TYPE_LIMIT, 'label' => 'Cuentas sociales'],
            self::TEAM_MEMBERS_MAX => ['type' => self::TYPE_LIMIT, 'label' => 'Miembros del equipo'],
            self::SCHEDULED_POSTS_MONTH => ['type' => self::TYPE_LIMIT, 'label' => 'Publicaciones/mes'],
            self::STORAGE_GB => ['type' => self::TYPE_LIMIT, 'label' => 'Almacenamiento (GB)'],
            self::AI_CREDITS_MONTH => ['type' => self::TYPE_LIMIT, 'label' => 'Créditos IA/mes'],
            self::KNOWLEDGE_DOCUMENTS_MAX => ['type' => self::TYPE_LIMIT, 'label' => 'Documentos del Brand Brain'],
            self::FEATURE_APPROVALS => ['type' => self::TYPE_BOOL, 'label' => 'Flujos de aprobación'],
            self::FEATURE_ANALYTICS_ADVANCED => ['type' => self::TYPE_BOOL, 'label' => 'Analítica avanzada'],
            self::FEATURE_INBOX => ['type' => self::TYPE_BOOL, 'label' => 'Inbox'],
            self::FEATURE_AUTOMATIONS => ['type' => self::TYPE_BOOL, 'label' => 'Automatizaciones'],
            self::FEATURE_BYOK => ['type' => self::TYPE_BOOL, 'label' => 'Claves propias (BYOK)'],
            self::FEATURE_API => ['type' => self::TYPE_BOOL, 'label' => 'API pública'],
            self::FEATURE_WHITE_LABEL => ['type' => self::TYPE_BOOL, 'label' => 'Marca blanca'],
            self::FEATURE_CUSTOM_ROLES => ['type' => self::TYPE_BOOL, 'label' => 'Roles personalizados'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::definitions());
    }

    public static function typeOf(string $key): string
    {
        return self::definitions()[$key]['type'] ?? self::TYPE_LIMIT;
    }

    public static function isBool(string $key): bool
    {
        return self::typeOf($key) === self::TYPE_BOOL;
    }
}
