<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Content\Jobs\PublishSocialPost;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\MediaLibrary\Services\MediaService;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Motor de publicación. Ejecuta cada PublicationTarget de forma independiente,
 * idempotente y con estados propios; consolida el estado del ContentItem
 * (PUBLISHED / PARTIAL / FAILED). Ver docs/12 y docs/15.
 */
class PublishingService
{
    public function __construct(
        private readonly SocialProviderManager $manager,
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Publica un target concreto. Idempotente: si ya está publicado, no hace nada.
     */
    public function publishTarget(PublicationTarget $target): void
    {
        if ($target->status === TargetStatus::PUBLISHED || $target->remote_id !== null) {
            return;
        }

        $variant = PostVariant::query()->withoutGlobalScopes()->with('media')->find($target->post_variant_id);
        $destination = SocialConnectionDestination::query()->withoutGlobalScopes()
            ->with('connection')->find($target->social_connection_destination_id);

        if ($variant === null || $destination === null || $destination->connection === null) {
            $this->markFailed($target, 'Destino o variante no disponible.');

            return;
        }

        $connection = $destination->connection;
        $adapter = $this->manager->adapter($connection->provider);
        if ($adapter === null) {
            $this->markFailed($target, 'Proveedor no disponible.');

            return;
        }

        $credentials = $this->manager->record($connection->provider)?->credentialMap() ?? [];
        $attemptNumber = $this->nextAttemptNumber($target);

        $target->update(['status' => TargetStatus::PUBLISHING->value]);

        try {
            $mediaUrls = $variant->media->map(fn ($m) => $this->media->temporaryUrl($m))->values()->all();
            $payload = new PublishPayload(
                body: $variant->body ?? '',
                mediaUrls: $mediaUrls,
                format: $variant->format,
                idempotencyKey: $target->public_id,
            );

            $result = $adapter->publish($connection->toTokens(), $destination->external_id, $payload, $credentials);

            $target->update([
                'status' => TargetStatus::PUBLISHED->value,
                'remote_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'published_at' => now(),
                'error' => null,
            ]);
            $this->recordAttempt($target, $attemptNumber, 'published', ['remote_id' => $result->remoteId]);
            $this->rollup($target);
        } catch (Throwable $e) {
            $this->markFailed($target, $e->getMessage(), $attemptNumber);
            $this->rollup($target);

            throw $e; // permite el reintento del job
        }
    }

    /**
     * Despacha los targets programados cuya fecha ya venció.
     */
    public function dispatchDue(): int
    {
        $targets = PublicationTarget::query()->withoutGlobalScopes()
            ->where('status', TargetStatus::SCHEDULED->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        $contentIds = [];
        foreach ($targets as $target) {
            PublishSocialPost::dispatch($target->id);
            $variant = PostVariant::query()->withoutGlobalScopes()->find($target->post_variant_id);
            if ($variant !== null) {
                $contentIds[$variant->content_item_id] = true;
            }
        }

        if ($contentIds !== []) {
            ContentItem::query()->withoutGlobalScopes()
                ->whereIn('id', array_keys($contentIds))
                ->where('status', ContentStatus::SCHEDULED->value)
                ->update(['status' => ContentStatus::PUBLISHING->value]);
        }

        return $targets->count();
    }

    /**
     * Publica ya un contenido: crea targets si faltan y despacha los jobs.
     */
    public function publishNow(ContentItem $content): void
    {
        DB::transaction(function () use ($content): void {
            $this->ensureTargets($content, now());
            $content->update(['status' => ContentStatus::PUBLISHING->value]);
            $this->audit->log(AuditAction::CONTENT_PUBLISHING, $content);
        });

        $content->loadMissing('variants');
        foreach ($content->variants as $variant) {
            foreach ($variant->targets()->where('status', TargetStatus::SCHEDULED->value)->get() as $target) {
                PublishSocialPost::dispatch($target->id);
            }
        }
    }

    /**
     * Consolida el estado del contenido a partir de sus targets.
     */
    public function rollup(PublicationTarget $target): void
    {
        $variant = PostVariant::query()->withoutGlobalScopes()->find($target->post_variant_id);
        if ($variant === null) {
            return;
        }

        $content = ContentItem::query()->withoutGlobalScopes()->find($variant->content_item_id);
        if ($content === null) {
            return;
        }

        $variantIds = PostVariant::query()->withoutGlobalScopes()
            ->where('content_item_id', $content->id)->pluck('id');
        $targets = PublicationTarget::query()->withoutGlobalScopes()
            ->whereIn('post_variant_id', $variantIds)->get();

        $total = $targets->count();
        $published = $targets->where('status', TargetStatus::PUBLISHED)->count();
        $failed = $targets->where('status', TargetStatus::FAILED)->count();

        if ($published + $failed < $total) {
            return; // aún hay targets en curso
        }

        $status = match (true) {
            $published === $total => ContentStatus::PUBLISHED,
            $published === 0 => ContentStatus::FAILED,
            default => ContentStatus::PARTIAL,
        };

        $content->update(['status' => $status->value]);
        $this->audit->log(
            $status === ContentStatus::PUBLISHED ? AuditAction::CONTENT_PUBLISHED : AuditAction::CONTENT_PUBLISH_FAILED,
            $content,
            ['published' => $published, 'failed' => $failed, 'total' => $total],
            organizationId: $content->organization_id,
        );

        // Notifica a otros módulos (Automations) sin acoplarlos.
        if ($status === ContentStatus::PUBLISHED || $status === ContentStatus::PARTIAL) {
            event(new ContentPublished($content, $status->value));
        }
    }

    private function ensureTargets(ContentItem $content, Carbon $when): void
    {
        $content->loadMissing('variants');
        foreach ($content->variants as $variant) {
            $destinations = SocialConnectionDestination::query()->withoutGlobalScopes()
                ->whereHas('connection', fn ($q) => $q
                    ->where('brand_id', $content->brand_id)
                    ->where('provider', $variant->provider)
                    ->where('status', 'connected'))
                ->get();

            foreach ($destinations as $destination) {
                PublicationTarget::query()->updateOrCreate(
                    ['post_variant_id' => $variant->id, 'social_connection_destination_id' => $destination->id],
                    [
                        'organization_id' => $content->organization_id,
                        'status' => TargetStatus::SCHEDULED->value,
                        'scheduled_at' => $when,
                    ],
                );
            }
        }
    }

    private function markFailed(PublicationTarget $target, string $message, ?int $attemptNumber = null): void
    {
        $target->update([
            'status' => TargetStatus::FAILED->value,
            'error' => Str::limit($message, 1000),
        ]);
        if ($attemptNumber !== null) {
            $this->recordAttempt($target, $attemptNumber, 'failed', ['error' => Str::limit($message, 500)]);
        }
    }

    private function nextAttemptNumber(PublicationTarget $target): int
    {
        return (int) DB::table('publication_attempts')
            ->where('publication_target_id', $target->id)
            ->max('attempt_number') + 1;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function recordAttempt(PublicationTarget $target, int $number, string $status, array $response): void
    {
        DB::table('publication_attempts')->insert([
            'publication_target_id' => $target->id,
            'attempt_number' => $number,
            'status' => $status,
            'response' => json_encode($response),
            'created_at' => now(),
        ]);
    }
}
