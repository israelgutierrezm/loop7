<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Events\ContentPublicationFailed;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Content\Jobs\PublishSocialPost;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\MediaLibrary\Services\MediaService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Contracts\PublishPayload;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
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
        private readonly SocialConnectionService $connections,
        private readonly PublicationPlanner $planner,
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    /**
     * Publica un target concreto. Idempotente: si ya está publicado, no hace nada.
     *
     * Con `$finalAttempt = false` (quedan reintentos del job) un error transitorio
     * no consolida el fallo: el target sigue "publicando" y el reintento decide.
     */
    public function publishTarget(PublicationTarget $target, bool $finalAttempt = true): void
    {
        if ($target->status === TargetStatus::PUBLISHED || $target->remote_id !== null) {
            return;
        }

        $variant = PostVariant::query()->withoutGlobalScopes()->with('media')->find($target->post_variant_id);

        // El contenido se eliminó con el job ya en cola: no se publica.
        if ($variant !== null) {
            $content = ContentItem::query()->withoutGlobalScopes()->find($variant->content_item_id);
            if ($content === null || $content->trashed()) {
                $target->update(['status' => TargetStatus::CANCELLED->value, 'error' => 'El contenido se eliminó.']);

                return;
            }
        }

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
            $this->rollup($target);

            return;
        }

        if ($connection->status !== ConnectionStatus::CONNECTED) {
            $this->markFailed($target, 'La conexión con la red social no está activa. Reconecta la cuenta.');
            $this->rollup($target);

            return;
        }

        $organization = Organization::query()->find($target->organization_id);
        if ($organization === null || ! $this->entitlements->hasAccess($organization)) {
            $this->markFailed($target, 'La suscripción de la organización no está activa.');
            $this->rollup($target);

            return;
        }

        $attemptNumber = $this->nextAttemptNumber($target);
        $target->update(['status' => TargetStatus::PUBLISHING->value]);

        try {
            $payload = new PublishPayload(
                body: $variant->body ?? '',
                // Minutos suficientes para que la red descargue (y procese) el archivo.
                mediaUrls: $variant->media->map(fn ($m) => $this->media->temporaryUrl($m, 120))->values()->all(),
                format: $variant->format,
                idempotencyKey: $target->public_id,
                mediaTypes: $variant->media->map(fn ($m) => $m->isVideo() ? 'video' : 'image')->values()->all(),
            );

            $result = $adapter->publish(
                $connection->toTokens($destination),
                $destination->external_id,
                $payload,
                $this->manager->credentials($connection->provider),
            );

            $target->update([
                'status' => TargetStatus::PUBLISHED->value,
                'remote_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'published_at' => now(),
                'error' => null,
            ]);
            $this->recordAttempt($target, $attemptNumber, 'published', ['remote_id' => $result->remoteId]);
            $this->rollup($target);
        } catch (SocialTokenExpiredException $e) {
            // Token caducado/revocado: reintentar no sirve; hay que reconectar.
            $this->connections->markExpired($connection, $e->getMessage());
            $this->markFailed($target, 'La conexión con la red social expiró. Reconecta la cuenta y vuelve a publicar.', $attemptNumber);
            $this->rollup($target);
        } catch (Throwable $e) {
            if ($finalAttempt) {
                $this->markFailed($target, $e->getMessage(), $attemptNumber);
                $this->rollup($target);
            } else {
                $target->update(['error' => Str::limit($e->getMessage(), 1000)]);
                $this->recordAttempt($target, $attemptNumber, 'failed', ['error' => Str::limit($e->getMessage(), 500)]);
            }

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
        $this->planner->assertPublishable($content);

        DB::transaction(function () use ($content): void {
            $this->planner->createTargets($content, now());
            // La fecha de salida es ahora (así aparece en el calendario).
            $content->update(['status' => ContentStatus::PUBLISHING->value, 'scheduled_at' => now()]);
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

        // Idempotente: el job puede consolidar dos veces el mismo resultado
        // (último intento + failed()); sólo el primero audita y emite eventos.
        if ($content->status === $status) {
            return;
        }

        $content->update(['status' => $status->value]);
        $this->audit->log(
            $status === ContentStatus::PUBLISHED ? AuditAction::CONTENT_PUBLISHED : AuditAction::CONTENT_PUBLISH_FAILED,
            $content,
            ['published' => $published, 'failed' => $failed, 'total' => $total],
            organizationId: $content->organization_id,
        );

        // Notifica a otros módulos (Automations, Notifications) sin acoplarlos.
        if ($status === ContentStatus::FAILED) {
            ContentPublicationFailed::dispatch($content);
        } else {
            event(new ContentPublished($content, $status->value));
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
