<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Brands\Models\BrandAudience;
use Illuminate\Database\Eloquent\Model;

class BrandAudiencesController extends AbstractBrandChildController
{
    protected function modelClass(): string
    {
        return BrandAudience::class;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'demographics' => ['nullable', 'array'],
        ];
    }

    /**
     * @param  BrandAudience  $model
     */
    protected function present(Model $model): array
    {
        return [
            'id' => $model->public_id,
            'name' => $model->name,
            'description' => $model->description,
            'demographics' => $model->demographics,
        ];
    }
}
