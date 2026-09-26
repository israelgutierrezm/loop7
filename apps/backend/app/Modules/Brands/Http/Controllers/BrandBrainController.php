<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\BrandAudience;
use App\Modules\Brands\Models\BrandGuideline;
use App\Modules\Brands\Models\BrandKnowledgeItem;
use App\Modules\Brands\Models\BrandProduct;
use App\Modules\Brands\Models\BrandService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandBrainController extends Controller
{
    use ResolvesBrand;

    /**
     * Contexto completo del Brand Brain de una Brand.
     */
    public function show(string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $id = $brandModel->id;

        $guidelines = BrandGuideline::query()->where('brand_id', $id)->first();

        return ApiResponse::success([
            'brand' => ['id' => $brandModel->public_id, 'name' => $brandModel->name],
            'guidelines' => $guidelines ? [
                'voice_tone' => $guidelines->voice_tone,
                'value_propositions' => $guidelines->value_propositions ?? [],
                'cta' => $guidelines->cta,
                'hashtags' => $guidelines->hashtags ?? [],
                'preferred_vocabulary' => $guidelines->preferred_vocabulary ?? [],
                'prohibited_terms' => $guidelines->prohibited_terms ?? [],
                'notes' => $guidelines->notes,
            ] : null,
            'audiences' => BrandAudience::query()->where('brand_id', $id)->latest('id')->get()
                ->map(fn (BrandAudience $a) => [
                    'id' => $a->public_id,
                    'name' => $a->name,
                    'description' => $a->description,
                    'demographics' => $a->demographics,
                ])->all(),
            'products' => BrandProduct::query()->where('brand_id', $id)->latest('id')->get()
                ->map(fn (BrandProduct $p) => [
                    'id' => $p->public_id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'price' => $p->price,
                    'url' => $p->url,
                ])->all(),
            'services' => BrandService::query()->where('brand_id', $id)->latest('id')->get()
                ->map(fn (BrandService $s) => [
                    'id' => $s->public_id,
                    'name' => $s->name,
                    'description' => $s->description,
                    'url' => $s->url,
                ])->all(),
            'knowledge' => BrandKnowledgeItem::query()->where('brand_id', $id)->latest('id')->get()
                ->map(fn (BrandKnowledgeItem $k) => [
                    'id' => $k->public_id,
                    'type' => $k->type,
                    'title' => $k->title,
                    'body' => $k->body,
                    'url' => $k->url,
                ])->all(),
        ]);
    }

    public function updateGuidelines(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('brands.update'), 403);

        $data = $request->validate([
            'voice_tone' => ['nullable', 'string', 'max:5000'],
            'value_propositions' => ['nullable', 'array'],
            'value_propositions.*' => ['string', 'max:500'],
            'cta' => ['nullable', 'string', 'max:1000'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:100'],
            'preferred_vocabulary' => ['nullable', 'array'],
            'preferred_vocabulary.*' => ['string', 'max:100'],
            'prohibited_terms' => ['nullable', 'array'],
            'prohibited_terms.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $guidelines = BrandGuideline::query()->updateOrCreate(
            ['brand_id' => $brandModel->id],
            array_merge($data, ['organization_id' => $brandModel->organization_id]),
        );

        return ApiResponse::success([
            'voice_tone' => $guidelines->voice_tone,
            'value_propositions' => $guidelines->value_propositions ?? [],
            'cta' => $guidelines->cta,
            'hashtags' => $guidelines->hashtags ?? [],
            'preferred_vocabulary' => $guidelines->preferred_vocabulary ?? [],
            'prohibited_terms' => $guidelines->prohibited_terms ?? [],
            'notes' => $guidelines->notes,
        ], 'Identidad de marca actualizada.');
    }
}
