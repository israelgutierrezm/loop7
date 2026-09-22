<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,

    // Módulos del monolito modular
    App\Modules\Identity\IdentityServiceProvider::class,
    App\Modules\Organizations\OrganizationServiceProvider::class,
    App\Modules\Brands\BrandServiceProvider::class,
    App\Modules\AccessControl\AccessControlServiceProvider::class,
    App\Modules\Audit\AuditServiceProvider::class,
    App\Modules\Billing\BillingServiceProvider::class,
    App\Modules\Payments\PaymentsServiceProvider::class,
    App\Modules\MediaLibrary\MediaLibraryServiceProvider::class,
    App\Modules\SocialConnections\SocialConnectionsServiceProvider::class,
    App\Modules\Campaigns\CampaignsServiceProvider::class,
    App\Modules\Content\ContentServiceProvider::class,
    App\Modules\Ai\AiServiceProvider::class,
    App\Modules\Analytics\AnalyticsServiceProvider::class,
    App\Modules\Inbox\InboxServiceProvider::class,
    App\Modules\Automations\AutomationsServiceProvider::class,
    App\Modules\Api\ApiServiceProvider::class,
    App\Modules\Compliance\ComplianceServiceProvider::class,
    App\Modules\PlatformAdmin\PlatformAdminServiceProvider::class,
];
