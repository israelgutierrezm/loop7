<?php

declare(strict_types=1);

namespace App\Modules\Brands;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Support\Providers\ModuleServiceProvider;

class BrandServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        Brand::class => BrandPolicy::class,
    ];
}
