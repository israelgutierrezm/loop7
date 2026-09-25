<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Content\Enums\ContentType;
use App\Modules\Content\Http\Resources\VariantPresenter;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Services\ContentService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContentController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly ContentService $content,
        private readonly AuditLogger $audit,
        private readonly VariantPresenter $variants,
    ) {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('content.view'), 403);

        $query = ContentItem::query()
            ->where('brand_id', $brandModel->id)
            ->with(['variants', 'campaign:id,public_id,name'])
            ->latest()
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        // Campaña: su public_id, o "none" para el contenido sin campaña.
        $campaign = $request->string('campaign')->toString();
        if ($campaign === 'none') {
            $query->whereNull('campaign_id');
        } elseif ($campaign !== '') {
            $query->whereHas('campaign', fn ($q) => $q->where('public_id', $campaign));
        }

        if ($request->filled('q')) {
            $term = addcslashes($request->string('q')->trim()->toString(), '%_\\');
            $query->where('title', 'like', "%{$term}%");
        }

        $perPage = min(50, max(1, (int) $request->integer('per_page', 20)));
        $items = $query->paginate($perPage)
            ->through(fn (ContentItem $c) => $this->present($c));

        return ApiResponse::paginated($items);
    }

    public function store(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('content.create'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'type' => ['nullable', Rule::in(ContentType::values())],
            'campaign' => ['nullable', 'string'],
            'variants' => ['nullable', 'array'],
            'variants.*.provider' => ['required_with:variants', 'string', Rule::in(array_keys(app(SocialProviderManager::class)->all()))],
            'variants.*.body' => ['nullable', 'string', 'max:20000'],
            'variants.*.format' => ['nullable', 'string', 'max:24'],
            'variants.*.media' => ['nullable', 'array'],
        ]);

        $content = $this->content->create($brandModel, $data, $request->user());

        return ApiResponse::success($this->present($content->load('variants')), 'Contenido creado.', status: 201);
    }

    public function show(string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless(request()->user()->can('content.view'), 403);

        return ApiResponse::success($this->present($model->load([
            'variants.media', 'variants.targets.destination', 'comments.user', 'campaign:id,public_id,name',
        ])));
    }

    public function update(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.update'), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'type' => ['sometimes', Rule::in(ContentType::values())],
            'campaign' => ['sometimes', 'nullable', 'string'],
        ]);

        // El texto sólo se edita antes de aprobar; la campaña (organización) siempre.
        $changes = array_intersect_key($data, array_flip(['title', 'body', 'type']));
        if ($changes !== [] && ! $model->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'El contenido no es editable en su estado actual.',
            ]);
        }

        if (array_key_exists('campaign', $data)) {
            $changes['campaign_id'] = $this->campaignId($model, $data['campaign']);
        }

        $model->update($changes);
        $this->audit->log(AuditAction::CONTENT_UPDATED, $model, ['changes' => array_keys($data)]);

        return ApiResponse::success($this->present($model->load(['variants', 'campaign:id,public_id,name'])), 'Contenido actualizado.');
    }

    /**
     * Campaña de la misma marca (null = sin campaña).
     */
    private function campaignId(ContentItem $content, ?string $campaignPublicId): ?int
    {
        if ($campaignPublicId === null || $campaignPublicId === '') {
            return null;
        }

        $id = Campaign::query()
            ->where('brand_id', $content->brand_id)
            ->where('public_id', $campaignPublicId)
            ->value('id');

        if ($id === null) {
            throw ValidationException::withMessages(['campaign' => 'La campaña no existe en esta marca.']);
        }

        return (int) $id;
    }

    public function destroy(string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless(request()->user()->can('content.delete'), 403);

        $this->content->delete($model);

        return ApiResponse::message('Contenido eliminado.');
    }

    private function resolve(string $publicId): ContentItem
    {
        $content = ContentItem::query()->with('brand')->where('public_id', $publicId)->firstOrFail();
        $this->authorizeBrand($content->brand);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ContentItem $content): array
    {
        return [
            'id' => $content->public_id,
            'brand' => $content->relationLoaded('brand') ? $content->brand?->public_id : null,
            'title' => $content->title,
            'body' => $content->body,
            'type' => $content->type->value,
            'type_label' => $content->type->label(),
            'campaign' => $content->relationLoaded('campaign') && $content->campaign !== null
                ? ['id' => $content->campaign->public_id, 'name' => $content->campaign->name]
                : null,
            'status' => $content->status->value,
            'status_label' => $content->status->label(),
            'editable' => $content->isEditable(),
            'scheduled_at' => $content->scheduled_at?->toIso8601String(),
            'created_at' => $content->created_at?->toIso8601String(),
            'variants' => $content->variants->map(fn (PostVariant $v) => $this->presentVariant($v))->all(),
            'comments' => $content->relationLoaded('comments')
                ? $content->comments->map(fn ($c) => [
                    'id' => $c->public_id,
                    'body' => $c->body,
                    'author' => $c->user?->name,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentVariant(PostVariant $variant): array
    {
        return $this->variants->present($variant);
    }
}
