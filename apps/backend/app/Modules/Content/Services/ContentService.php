<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\MediaLibrary\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

class ContentService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Brand $brand, array $data, User $user): ContentItem
    {
        return DB::transaction(function () use ($brand, $data, $user): ContentItem {
            $content = ContentItem::query()->create([
                'organization_id' => $brand->organization_id,
                'brand_id' => $brand->id,
                'campaign_id' => $this->resolveCampaignId($brand, $data['campaign'] ?? null),
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'type' => $data['type'] ?? 'post',
                'status' => ContentStatus::DRAFT->value,
                'created_by_user_id' => $user->id,
            ]);

            foreach ($data['variants'] ?? [] as $variant) {
                $this->addVariant($content, $variant);
            }

            $this->audit->log(AuditAction::CONTENT_CREATED, $content, ['title' => $content->title]);

            return $content;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addVariant(ContentItem $content, array $data): PostVariant
    {
        $variant = PostVariant::query()->create([
            'organization_id' => $content->organization_id,
            'content_item_id' => $content->id,
            'provider' => $data['provider'],
            'body' => $data['body'] ?? $content->body,
            'format' => $data['format'] ?? 'text',
            'options' => $data['options'] ?? null,
        ]);

        if (! empty($data['media'])) {
            $this->syncMedia($variant, $data['media']);
        }

        return $variant;
    }

    /**
     * @param  list<string>  $mediaPublicIds
     */
    public function syncMedia(PostVariant $variant, array $mediaPublicIds): void
    {
        // Sólo media de la misma marca del contenido (y de la Organization por el scope).
        $brandId = ContentItem::query()->withoutGlobalScopes()->whereKey($variant->content_item_id)->value('brand_id');
        $assets = MediaAsset::query()
            ->where('brand_id', $brandId)
            ->whereIn('public_id', $mediaPublicIds)
            ->get();

        $sync = [];
        $position = 0;
        foreach ($mediaPublicIds as $publicId) {
            $asset = $assets->firstWhere('public_id', $publicId);
            if ($asset !== null) {
                $sync[$asset->id] = ['position' => $position++];
            }
        }

        $variant->media()->sync($sync);
    }

    private function resolveCampaignId(Brand $brand, ?string $campaignPublicId): ?int
    {
        if ($campaignPublicId === null) {
            return null;
        }

        return \App\Modules\Campaigns\Models\Campaign::query()
            ->where('brand_id', $brand->id)
            ->where('public_id', $campaignPublicId)
            ->value('id');
    }
}
