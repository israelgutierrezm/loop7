<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Services\PublishingService;
use App\Modules\Content\Services\WorkflowService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ContentWorkflowController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly PublishingService $publishing,
    ) {
    }

    public function submit(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.submit_for_review'), 403);

        $this->workflow->submit($model, $request->user());

        return ApiResponse::message('Contenido enviado a revisión.');
    }

    public function approve(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.approve'), 403);

        $this->workflow->approve($model, $request->user());

        return ApiResponse::message('Contenido aprobado.');
    }

    public function requestChanges(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.reject'), 403);

        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $this->workflow->requestChanges($model, $request->user(), $data['note']);

        return ApiResponse::message('Se solicitaron cambios.');
    }

    public function comment(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.view'), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $comment = $this->workflow->comment($model, $request->user(), $data['body']);

        return ApiResponse::success([
            'id' => $comment->public_id,
            'body' => $comment->body,
            'author' => $request->user()->name,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 'Comentario añadido.', status: 201);
    }

    public function schedule(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.schedule'), 403);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $this->workflow->schedule($model, Carbon::parse($data['scheduled_at']), $request->user());

        return ApiResponse::message('Contenido programado.');
    }

    public function publishNow(Request $request, string $content): JsonResponse
    {
        $model = $this->resolve($content);
        abort_unless($request->user()->can('content.publish_now'), 403);

        if (! in_array($model->status, [ContentStatus::APPROVED, ContentStatus::SCHEDULED], true)) {
            throw ValidationException::withMessages([
                'status' => 'El contenido debe estar aprobado o programado para publicarse ahora.',
            ]);
        }

        $this->publishing->publishNow($model);

        return ApiResponse::message('Publicación en marcha.');
    }

    private function resolve(string $publicId): ContentItem
    {
        $content = ContentItem::query()->with('brand')->where('public_id', $publicId)->firstOrFail();
        $this->authorizeBrand($content->brand);

        return $content;
    }
}
