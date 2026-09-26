<?php

declare(strict_types=1);

namespace App\Modules\Brands;

use App\Modules\Brands\Listeners\DeleteBrandsOfDeletedOrganization;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class BrandServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        Brand::class => BrandPolicy::class,
    ];

    protected function bootModule(): void
    {
        Event::listen(OrganizationDeleted::class, DeleteBrandsOfDeletedOrganization::class);
    }
}
