<?php

declare(strict_types=1);

namespace App\Modules\Organizations;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Policies\OrganizationPolicy;
use App\Support\Providers\ModuleServiceProvider;

class OrganizationServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        Organization::class => OrganizationPolicy::class,
    ];
}
