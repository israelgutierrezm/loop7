<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Events\PublicationDeletedRemotely;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\SocialConnections\Contracts\DeletesRemotePosts;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Borra de su red una publicación hecha desde Loop7 (docs/05). Es síncrono,
 * como responder en el inbox: la persona ve el resultado al momento. Es
 * idempotente y usa un bloqueo por destino para no borrar (ni auditar) dos veces.
 */
class RemotePostDeletion
{
    private const LOCK_SECONDS = 60;

    /** Estados en los que el contenido sigue (o seguirá) en alguna red. */
    private const LIVE = [TargetStatus::PENDING, TargetStatus::SCHEDULED, TargetStatus::PUBLISHING, TargetStatus::PUBLISHED];

    public function __construct(
        private readonly SocialProviderManager $providers,
        private readonly SocialConnectionService $connections,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * ¿La red permite borrar publicaciones desde Loop7?
     */
    public function supports(string $provider): bool
    {
        return $this->providers->adapter($provider) instanceof DeletesRemotePosts;
    }

    /**
     * @throws ValidationException si no se puede borrar (con el motivo para la persona)
     */
    public function delete(PublicationTarget $target): PublicationTarget
    {
        $lock = Cache::lock('publication-remote-delete:' . $target->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw $this->refusal('Ya se está borrando esta publicación.');
        }

        try {
            $target->refresh();
            // Otro borrado ya terminó: nada que hacer.
            if ($target->status !== TargetStatus::DELETED) {
                $this->deleteLocked($target);
            }
        } finally {
            $lock->release();
        }

        return $target;
    }

    private function deleteLocked(PublicationTarget $target): void
    {
        if ($target->status !== TargetStatus::PUBLISHED || $target->remote_id === null) {
            throw $this->refusal('Sólo se puede borrar de la red algo ya publicado.');
        }

        $variant = PostVariant::query()->withoutGlobalScopes()->findOrFail($target->post_variant_id);
        $provider = $variant->provider;
        $name = $this->providers->record($provider)->name ?? ucfirst($provider);
        $adapter = $this->providers->adapter($provider);
        if (! $adapter instanceof DeletesRemotePosts) {
            throw $this->refusal("{$name} no permite borrar publicaciones desde otras apps: bórrala directamente en {$name}.");
        }

        $destination = $target->social_connection_destination_id !== null
            ? SocialConnectionDestination::query()->withoutGlobalScopes()->find($target->social_connection_destination_id)
            : null;
        $connection = $destination !== null
            ? SocialConnection::query()->withoutGlobalScopes()->find($destination->social_connection_id)
            : null;
        if ($connection === null || $connection->status !== ConnectionStatus::CONNECTED) {
            throw $this->refusal("La cuenta de {$name} no está conectada: reconéctala para borrar la publicación.");
        }

        try {
            $adapter->deleteRemotePost(
                $this->connections->freshTokens($connection, $destination),
                $target->remote_id,
                $this->providers->credentials($provider),
            );
        } catch (SocialTokenExpiredException $e) {
            $this->connections->markExpired($connection, $e->getMessage());

            throw $this->refusal("{$name} rechazó el acceso de la cuenta: reconéctala y vuelve a intentarlo.");
        } catch (SocialProviderException $e) {
            throw $this->refusal($e->getMessage());
        }

        $content = ContentItem::query()->withoutGlobalScopes()->findOrFail($variant->content_item_id);
        DB::transaction(function () use ($target, $content): void {
            $target->update([
                'status' => TargetStatus::DELETED->value,
                'remote_deleted_at' => now(),
                'error' => null,
            ]);
            $this->settle($content);
        });

        $this->audit->log(AuditAction::PUBLICATION_REMOTE_DELETED, $content, [
            'target' => $target->public_id,
            'provider' => $provider,
            'destination' => $destination->name,
        ], organizationId: $content->organization_id);

        PublicationDeletedRemotely::dispatch($content, $target, $provider);
    }

    /**
     * Si ya no queda nada en las redes (ni por publicar), el contenido pasa a «Retirado».
     */
    private function settle(ContentItem $content): void
    {
        $live = PublicationTarget::query()->withoutGlobalScopes()
            ->whereIn('post_variant_id', PostVariant::query()->withoutGlobalScopes()->where('content_item_id', $content->id)->select('id'))
            ->whereIn('status', array_map(fn (TargetStatus $s) => $s->value, self::LIVE))
            ->exists();
        if (! $live) {
            $content->update(['status' => ContentStatus::UNPUBLISHED->value]);
        }
    }

    private function refusal(string $message): ValidationException
    {
        return ValidationException::withMessages(['target' => $message]);
    }
}
