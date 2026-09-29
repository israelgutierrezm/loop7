<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\RemotePostDeletion;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acciones sobre una publicación concreta (un destino de una variante).
 */
class PublicationTargetsController extends Controller
{
    use ResolvesBrand;

    public function __construct(private readonly RemotePostDeletion $deletion)
    {
    }

    /**
     * Borra de su red una publicación ya hecha (permiso content.delete).
     */
    public function destroyRemote(Request $request, string $target): JsonResponse
    {
        // OrganizationScope: sólo publicaciones de la organización actual (anti-IDOR).
        $model = PublicationTarget::query()->where('public_id', $target)->firstOrFail();
        $content = PostVariant::query()->with('contentItem.brand')->findOrFail($model->post_variant_id)->contentItem;
        abort_if($content === null, 404);
        $this->authorizeBrand($content->brand);
        abort_unless($request->user()->can('content.delete'), 403);

        $model = $this->deletion->delete($model);
        $content->refresh();

        return ApiResponse::success([
            'id' => $model->public_id,
            'status' => $model->status->value,
            'status_label' => $model->status->label(),
            'remote_deleted_at' => $model->remote_deleted_at?->toIso8601String(),
            'content_status' => $content->status->value,
            'content_status_label' => $content->status->label(),
        ], 'Publicación borrada de la red.');
    }
}
