<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Permissions;

use App\Modules\AccessControl\Enums\OrganizationRole;

/**
 * Mapa de permisos por defecto para cada rol predefinido de Organization.
 *
 * Estos son los conjuntos base; a futuro podrán existir roles custom.
 */
final class RolePermissions
{
    /**
     * @return array<string, list<string>> rol => permisos
     */
    public static function map(): array
    {
        $all = Permission::all();

        $viewer = [
            Permission::ORGANIZATION_VIEW,
            Permission::MEMBERS_VIEW,
            Permission::BRANDS_VIEW,
            Permission::SOCIAL_ACCOUNTS_VIEW,
            Permission::CONTENT_VIEW,
            Permission::CAMPAIGNS_VIEW,
            Permission::ANALYTICS_VIEW,
        ];

        $contentCreator = array_merge($viewer, [
            Permission::CONTENT_CREATE,
            Permission::CONTENT_UPDATE,
            Permission::CONTENT_AI_GENERATE,
            Permission::CONTENT_SUBMIT_FOR_REVIEW,
            Permission::AI_USE,
            Permission::AI_GENERATE_TEXT,
            Permission::AI_GENERATE_IMAGE,
        ]);

        $approver = array_merge($viewer, [
            Permission::CONTENT_APPROVE,
            Permission::CONTENT_REJECT,
        ]);

        $publisher = array_merge($viewer, [
            Permission::CONTENT_SCHEDULE,
            Permission::CONTENT_PUBLISH_NOW,
        ]);

        $analyst = array_merge($viewer, [
            Permission::ANALYTICS_EXPORT,
            Permission::SOCIAL_ACCOUNTS_ANALYTICS,
        ]);

        $billing = [
            Permission::ORGANIZATION_VIEW,
            Permission::BILLING_VIEW,
            Permission::BILLING_INVOICES,
            Permission::BILLING_CHANGE_PLAN,
            Permission::BILLING_PAYMENT_METHODS,
            Permission::BILLING_CANCEL_SUBSCRIPTION,
        ];

        $manager = array_values(array_unique(array_merge(
            $contentCreator,
            $approver,
            $publisher,
            $analyst,
            [
                Permission::MEMBERS_INVITE,
                Permission::ROLES_VIEW,
                Permission::ROLES_ASSIGN,
                Permission::BRANDS_CREATE,
                Permission::BRANDS_UPDATE,
                Permission::BRANDS_MANAGE_ACCESS,
                Permission::SOCIAL_ACCOUNTS_CONNECT,
                Permission::SOCIAL_ACCOUNTS_RECONNECT,
                Permission::SOCIAL_ACCOUNTS_DISCONNECT,
                Permission::SOCIAL_ACCOUNTS_MANAGE,
                Permission::SOCIAL_ACCOUNTS_INBOX,
                Permission::CONTENT_DELETE,
                Permission::CAMPAIGNS_CREATE,
                Permission::CAMPAIGNS_UPDATE,
                Permission::CAMPAIGNS_DELETE,
                Permission::AI_GENERATE_VIDEO,
                Permission::AI_VIEW_USAGE,
                Permission::AUTOMATIONS_VIEW,
                Permission::AUTOMATIONS_CREATE,
                Permission::AUTOMATIONS_UPDATE,
                Permission::AUTOMATIONS_DELETE,
            ],
        )));

        // ADMIN: todo salvo eliminar/transferir la Organization (reservado a OWNER).
        $admin = array_values(array_diff($all, [
            Permission::ORGANIZATION_DELETE,
            Permission::ORGANIZATION_TRANSFER_OWNERSHIP,
        ]));

        return [
            OrganizationRole::OWNER->value => $all,
            OrganizationRole::ADMIN->value => $admin,
            OrganizationRole::MANAGER->value => $manager,
            OrganizationRole::APPROVER->value => $approver,
            OrganizationRole::PUBLISHER->value => $publisher,
            OrganizationRole::CONTENT_CREATOR->value => $contentCreator,
            OrganizationRole::ANALYST->value => $analyst,
            OrganizationRole::BILLING->value => $billing,
            OrganizationRole::VIEWER->value => $viewer,
        ];
    }
}
