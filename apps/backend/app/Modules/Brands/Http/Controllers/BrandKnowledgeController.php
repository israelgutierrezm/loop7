<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Brands\Models\BrandKnowledgeItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class BrandKnowledgeController extends AbstractBrandChildController
{
    protected function modelClass(): string
    {
        return BrandKnowledgeItem::class;
    }

    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['faq', 'url', 'note'])],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * @param  BrandKnowledgeItem  $model
     */
    protected function present(Model $model): array
    {
        return [
            'id' => $model->public_id,
            'type' => $model->type,
            'title' => $model->title,
            'body' => $model->body,
            'url' => $model->url,
        ];
    }
}
