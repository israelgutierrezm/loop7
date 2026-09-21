<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Brands\Models\BrandProduct;
use Illuminate\Database\Eloquent\Model;

class BrandProductsController extends AbstractBrandChildController
{
    protected function modelClass(): string
    {
        return BrandProduct::class;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'url', 'max:255'],
        ];
    }

    /**
     * @param  BrandProduct  $model
     */
    protected function present(Model $model): array
    {
        return [
            'id' => $model->public_id,
            'name' => $model->name,
            'description' => $model->description,
            'price' => $model->price,
            'url' => $model->url,
        ];
    }
}
