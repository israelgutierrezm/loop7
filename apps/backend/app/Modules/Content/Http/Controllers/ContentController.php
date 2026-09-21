<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Content\Enums\ContentType;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Services\ContentService;
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
    ) {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('content.view'), 403);

        $query = ContentItem::query()
            ->where('brand_id', $brandModel->id)
            ->with('variants')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $items = $query->paginate((int) $request->integer('per_page', 20))
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
            'variants.*.provider' => ['required_with:variants', 'string', 'max:32'],
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
            'variants.media', 'variants.targets.destination', 'comments.user',
        ])));
    }

    public function update(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.update'), 403);

        if (! $model->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'El contenido no es editable en su estado actual.',
            ]);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'type' => ['sometimes', Rule::in(ContentType::values())],
        ]);

        $model->update($data);
        $this->audit->log(AuditAction::CONTENT_UPDATED, $model, ['changes' => array_keys($data)]);

        return ApiResponse::success($this->present($model->load('variants')), 'Contenido actualizado.');
    }

    public function destroy(string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless(request()->user()->can('content.delete'), 403);

        $this->audit->log(AuditAction::CONTENT_DELETED, $model, ['title' => $model->title]);
        $model->delete();

        return ApiResponse::message('Contenido eliminado.');
    }

    private function resolve(string $publicId): ContentItem
    {
        return ContentItem::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ContentItem $content): array
    {
        return [
            'id' => $content->public_id,
            'title' => $content->title,
            'body' => $content->body,
            'type' => $content->type->value,
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
        return [
            'id' => $variant->public_id,
            'provider' => $variant->provider,
            'body' => $variant->body,
            'format' => $variant->format,
            'media' => $variant->relationLoaded('media')
                ? $variant->media->map(fn ($m) => ['id' => $m->public_id, 'original_name' => $m->original_name])->all()
                : [],
            'targets' => $variant->relationLoaded('targets')
                ? $variant->targets->map(fn ($t) => [
                    'id' => $t->public_id,
                    'status' => $t->status->value,
                    'status_label' => $t->status->label(),
                    'destination' => $t->destination?->name,
                    'remote_url' => $t->remote_url,
                    'error' => $t->error,
                ])->all()
                : [],
        ];
    }
}
