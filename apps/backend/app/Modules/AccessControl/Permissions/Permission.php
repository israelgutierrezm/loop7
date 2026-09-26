<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Permissions;

/**
 * Catálogo central de permisos granulares (docs/04_ROLES_PERMISOS.md).
 *
 * La autorización real se basa en estos permisos, no en el nombre del rol.
 */
final class Permission
{
    // Organization
    public const ORGANIZATION_VIEW = 'organization.view';
    public const ORGANIZATION_UPDATE = 'organization.update';
    public const ORGANIZATION_DELETE = 'organization.delete';
    public const ORGANIZATION_TRANSFER_OWNERSHIP = 'organization.transfer_ownership';

    // Members / roles
    public const MEMBERS_VIEW = 'members.view';
    public const MEMBERS_INVITE = 'members.invite';
    public const MEMBERS_UPDATE = 'members.update';
    public const MEMBERS_REMOVE = 'members.remove';
    public const ROLES_VIEW = 'roles.view';
    public const ROLES_CREATE = 'roles.create';
    public const ROLES_UPDATE = 'roles.update';
    public const ROLES_DELETE = 'roles.delete';
    public const ROLES_ASSIGN = 'roles.assign';

    // Brands
    public const BRANDS_VIEW = 'brands.view';
    public const BRANDS_CREATE = 'brands.create';
    public const BRANDS_UPDATE = 'brands.update';
    public const BRANDS_DELETE = 'brands.delete';
    public const BRANDS_MANAGE_ACCESS = 'brands.manage_access';

    // Social accounts
    public const SOCIAL_ACCOUNTS_VIEW = 'social_accounts.view';
    public const SOCIAL_ACCOUNTS_CONNECT = 'social_accounts.connect';
    public const SOCIAL_ACCOUNTS_RECONNECT = 'social_accounts.reconnect';
    public const SOCIAL_ACCOUNTS_DISCONNECT = 'social_accounts.disconnect';
    public const SOCIAL_ACCOUNTS_MANAGE = 'social_accounts.manage';
    public const SOCIAL_ACCOUNTS_ANALYTICS = 'social_accounts.analytics';
    public const SOCIAL_ACCOUNTS_INBOX = 'social_accounts.inbox';

    // Content
    public const CONTENT_VIEW = 'content.view';
    public const CONTENT_CREATE = 'content.create';
    public const CONTENT_UPDATE = 'content.update';
    public const CONTENT_DELETE = 'content.delete';
    public const CONTENT_AI_GENERATE = 'content.ai_generate';
    public const CONTENT_SUBMIT_FOR_REVIEW = 'content.submit_for_review';
    public const CONTENT_APPROVE = 'content.approve';
    public const CONTENT_REJECT = 'content.reject';
    public const CONTENT_SCHEDULE = 'content.schedule';
    public const CONTENT_PUBLISH_NOW = 'content.publish_now';

    // Campaigns
    public const CAMPAIGNS_VIEW = 'campaigns.view';
    public const CAMPAIGNS_CREATE = 'campaigns.create';
    public const CAMPAIGNS_UPDATE = 'campaigns.update';
    public const CAMPAIGNS_DELETE = 'campaigns.delete';

    // Analytics
    public const ANALYTICS_VIEW = 'analytics.view';
    public const ANALYTICS_EXPORT = 'analytics.export';

    // Billing
    public const BILLING_VIEW = 'billing.view';
    public const BILLING_INVOICES = 'billing.invoices';
    public const BILLING_CHANGE_PLAN = 'billing.change_plan';
    public const BILLING_PAYMENT_METHODS = 'billing.payment_methods';
    public const BILLING_CANCEL_SUBSCRIPTION = 'billing.cancel_subscription';

    // Automations
    public const AUTOMATIONS_VIEW = 'automations.view';
    public const AUTOMATIONS_CREATE = 'automations.create';
    public const AUTOMATIONS_UPDATE = 'automations.update';
    public const AUTOMATIONS_DELETE = 'automations.delete';

    // API pública
    public const API_MANAGE = 'api.manage';

    // AI
    public const AI_USE = 'ai.use';
    public const AI_GENERATE_TEXT = 'ai.generate_text';
    public const AI_GENERATE_IMAGE = 'ai.generate_image';
    public const AI_GENERATE_VIDEO = 'ai.generate_video';
    public const AI_VIEW_USAGE = 'ai.view_usage';
    public const AI_MANAGE_OWN_KEYS = 'ai.manage_own_keys';

    /**
     * Permisos agrupados por dominio (para UI y seeding).
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'organization' => [
                self::ORGANIZATION_VIEW,
                self::ORGANIZATION_UPDATE,
                self::ORGANIZATION_DELETE,
                self::ORGANIZATION_TRANSFER_OWNERSHIP,
            ],
            'members' => [
                self::MEMBERS_VIEW,
                self::MEMBERS_INVITE,
                self::MEMBERS_UPDATE,
                self::MEMBERS_REMOVE,
                self::ROLES_VIEW,
                self::ROLES_CREATE,
                self::ROLES_UPDATE,
                self::ROLES_DELETE,
                self::ROLES_ASSIGN,
            ],
            'brands' => [
                self::BRANDS_VIEW,
                self::BRANDS_CREATE,
                self::BRANDS_UPDATE,
                self::BRANDS_DELETE,
                self::BRANDS_MANAGE_ACCESS,
            ],
            'social_accounts' => [
                self::SOCIAL_ACCOUNTS_VIEW,
                self::SOCIAL_ACCOUNTS_CONNECT,
                self::SOCIAL_ACCOUNTS_RECONNECT,
                self::SOCIAL_ACCOUNTS_DISCONNECT,
                self::SOCIAL_ACCOUNTS_MANAGE,
                self::SOCIAL_ACCOUNTS_ANALYTICS,
                self::SOCIAL_ACCOUNTS_INBOX,
            ],
            'content' => [
                self::CONTENT_VIEW,
                self::CONTENT_CREATE,
                self::CONTENT_UPDATE,
                self::CONTENT_DELETE,
                self::CONTENT_AI_GENERATE,
                self::CONTENT_SUBMIT_FOR_REVIEW,
                self::CONTENT_APPROVE,
                self::CONTENT_REJECT,
                self::CONTENT_SCHEDULE,
                self::CONTENT_PUBLISH_NOW,
            ],
            'campaigns' => [
                self::CAMPAIGNS_VIEW,
                self::CAMPAIGNS_CREATE,
                self::CAMPAIGNS_UPDATE,
                self::CAMPAIGNS_DELETE,
            ],
            'analytics' => [
                self::ANALYTICS_VIEW,
                self::ANALYTICS_EXPORT,
            ],
            'automations' => [
                self::AUTOMATIONS_VIEW,
                self::AUTOMATIONS_CREATE,
                self::AUTOMATIONS_UPDATE,
                self::AUTOMATIONS_DELETE,
            ],
            'api' => [
                self::API_MANAGE,
            ],
            'billing' => [
                self::BILLING_VIEW,
                self::BILLING_INVOICES,
                self::BILLING_CHANGE_PLAN,
                self::BILLING_PAYMENT_METHODS,
                self::BILLING_CANCEL_SUBSCRIPTION,
            ],
            'ai' => [
                self::AI_USE,
                self::AI_GENERATE_TEXT,
                self::AI_GENERATE_IMAGE,
                self::AI_GENERATE_VIDEO,
                self::AI_VIEW_USAGE,
                self::AI_MANAGE_OWN_KEYS,
            ],
        ];
    }

    /**
     * Lista plana de todos los permisos.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /**
     * Nombre legible de cada grupo (para el editor de roles).
     *
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'organization' => 'Organización',
            'members' => 'Equipo y roles',
            'brands' => 'Marcas',
            'social_accounts' => 'Redes sociales',
            'content' => 'Contenido',
            'campaigns' => 'Campañas',
            'analytics' => 'Analítica',
            'automations' => 'Automatizaciones',
            'api' => 'API',
            'billing' => 'Facturación',
            'ai' => 'Inteligencia artificial',
        ];
    }

    /**
     * Nombre legible de cada permiso.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ORGANIZATION_VIEW => 'Ver la organización',
            self::ORGANIZATION_UPDATE => 'Editar la organización y la auditoría',
            self::ORGANIZATION_DELETE => 'Eliminar la organización',
            self::ORGANIZATION_TRANSFER_OWNERSHIP => 'Transferir la propiedad',
            self::MEMBERS_VIEW => 'Ver el equipo',
            self::MEMBERS_INVITE => 'Invitar miembros',
            self::MEMBERS_UPDATE => 'Gestionar miembros',
            self::MEMBERS_REMOVE => 'Quitar o suspender miembros',
            self::ROLES_VIEW => 'Ver roles',
            self::ROLES_CREATE => 'Crear roles personalizados',
            self::ROLES_UPDATE => 'Editar roles personalizados',
            self::ROLES_DELETE => 'Eliminar roles personalizados',
            self::ROLES_ASSIGN => 'Asignar roles',
            self::BRANDS_VIEW => 'Ver marcas',
            self::BRANDS_CREATE => 'Crear marcas',
            self::BRANDS_UPDATE => 'Editar marcas y su Brand Brain',
            self::BRANDS_DELETE => 'Eliminar marcas',
            self::BRANDS_MANAGE_ACCESS => 'Gestionar el acceso a marcas',
            self::SOCIAL_ACCOUNTS_VIEW => 'Ver cuentas sociales',
            self::SOCIAL_ACCOUNTS_CONNECT => 'Conectar cuentas',
            self::SOCIAL_ACCOUNTS_RECONNECT => 'Reconectar cuentas',
            self::SOCIAL_ACCOUNTS_DISCONNECT => 'Desconectar cuentas',
            self::SOCIAL_ACCOUNTS_MANAGE => 'Gestionar cuentas',
            self::SOCIAL_ACCOUNTS_ANALYTICS => 'Analítica de cuentas',
            self::SOCIAL_ACCOUNTS_INBOX => 'Usar el inbox',
            self::CONTENT_VIEW => 'Ver contenido',
            self::CONTENT_CREATE => 'Crear contenido',
            self::CONTENT_UPDATE => 'Editar contenido',
            self::CONTENT_DELETE => 'Eliminar contenido',
            self::CONTENT_AI_GENERATE => 'Generar contenido con IA',
            self::CONTENT_SUBMIT_FOR_REVIEW => 'Enviar a revisión',
            self::CONTENT_APPROVE => 'Aprobar contenido',
            self::CONTENT_REJECT => 'Pedir cambios',
            self::CONTENT_SCHEDULE => 'Programar publicaciones',
            self::CONTENT_PUBLISH_NOW => 'Publicar al momento',
            self::CAMPAIGNS_VIEW => 'Ver campañas',
            self::CAMPAIGNS_CREATE => 'Crear campañas',
            self::CAMPAIGNS_UPDATE => 'Editar campañas',
            self::CAMPAIGNS_DELETE => 'Eliminar campañas',
            self::ANALYTICS_VIEW => 'Ver analítica',
            self::ANALYTICS_EXPORT => 'Exportar analítica',
            self::AUTOMATIONS_VIEW => 'Ver automatizaciones',
            self::AUTOMATIONS_CREATE => 'Crear automatizaciones',
            self::AUTOMATIONS_UPDATE => 'Editar automatizaciones',
            self::AUTOMATIONS_DELETE => 'Eliminar automatizaciones',
            self::API_MANAGE => 'Gestionar API keys',
            self::BILLING_VIEW => 'Ver facturación',
            self::BILLING_INVOICES => 'Ver facturas',
            self::BILLING_CHANGE_PLAN => 'Cambiar de plan',
            self::BILLING_PAYMENT_METHODS => 'Métodos de pago',
            self::BILLING_CANCEL_SUBSCRIPTION => 'Cancelar la suscripción',
            self::AI_USE => 'Usar la IA',
            self::AI_GENERATE_TEXT => 'Generar textos',
            self::AI_GENERATE_IMAGE => 'Generar imágenes',
            self::AI_GENERATE_VIDEO => 'Generar videos',
            self::AI_VIEW_USAGE => 'Ver el consumo de IA',
            self::AI_MANAGE_OWN_KEYS => 'Gestionar claves propias de IA',
        ];
    }
}
