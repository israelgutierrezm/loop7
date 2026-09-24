<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Resources;

use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\MediaLibrary\Services\MediaService;

/**
 * Representación de una variante por red para la API (compartida por los
 * controladores de contenido y de variantes).
 */
class VariantPresenter
{
    public function __construct(private readonly MediaService $media)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PostVariant $variant): array
    {
        return [
            'id' => $variant->public_id,
            'provider' => $variant->provider,
            'body' => $variant->body,
            'format' => $variant->format,
            'options' => $variant->options,
            'media' => $variant->relationLoaded('media')
                ? $variant->media->map(fn (MediaAsset $m) => [
                    'id' => $m->public_id,
                    'original_name' => $m->original_name,
                    'is_image' => $m->isImage(),
                    'is_video' => $m->isVideo(),
                    'url' => $this->media->temporaryUrl($m),
                ])->values()->all()
                : [],
            'targets' => $variant->relationLoaded('targets')
                ? $variant->targets->map(fn (PublicationTarget $t) => [
                    'id' => $t->public_id,
                    'status' => $t->status->value,
                    'status_label' => $t->status->label(),
                    'destination' => $t->destination?->name,
                    'scheduled_at' => $t->scheduled_at?->toIso8601String(),
                    'published_at' => $t->published_at?->toIso8601String(),
                    'remote_url' => $t->remote_url,
                    'error' => $t->error,
                ])->values()->all()
                : [],
        ];
    }
}
