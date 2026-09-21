<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Brands\Models\BrandService;
use Illuminate\Database\Eloquent\Model;

class BrandServicesController extends AbstractBrandChildController
{
    protected function modelClass(): string
    {
        return BrandService::class;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'url' => ['nullable', 'url', 'max:255'],
        ];
    }

    /**
     * @param  BrandService  $model
     */
    protected function present(Model $model): array
    {
        return [
            'id' => $model->public_id,
            'name' => $model->name,
            'description' => $model->description,
            'url' => $model->url,
        ];
    }
}
