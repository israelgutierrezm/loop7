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
    App\Modules\PlatformAdmin\PlatformAdminServiceProvider::class,
];
