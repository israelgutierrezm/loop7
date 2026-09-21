<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Inbox\Enums\ConversationStatus;
use App\Modules\Inbox\Jobs\SyncInboxConversations;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\SocialConnections\Contracts\InboxThread;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Exceptions\ProviderNotConfiguredException;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Support\Str;

/**
 * Sincroniza y opera el inbox (docs/05). Cada conversación se resuelve en el
 * contexto de su Organization/Brand; los proveedores sin configurar se omiten.
 */
class InboxService
{
    public function __construct(private readonly SocialProviderManager $manager)
    {
    }

    /**
     * Sincroniza las conversaciones de todos los destinos conectados de una Brand.
     *
     * @return array{conversations: int, messages: int}
     */
    public function syncBrand(Brand $brand): array
    {
        $conversations = 0;
        $messages = 0;

        foreach ($this->brandDestinations($brand) as $destination) {
            $connection = SocialConnection::query()->withoutGlobalScopes()->find($destination->social_connection_id);
            if ($connection === null || $connection->status !== ConnectionStatus::CONNECTED) {
                continue;
            }

            $adapter = $this->manager->adapter($connection->provider);
            if ($adapter === null) {
                continue;
            }

            $credentials = $this->manager->record($connection->provider)?->credentialMap() ?? [];

            try {
                $threads = $adapter->fetchConversations($connection->toTokens(), $destination->external_id, $credentials);
            } catch (ProviderNotConfiguredException) {
                continue;
            }

            foreach ($threads as $thread) {
                [$isNewConv, $newMessages] = $this->storeThread($connection, $destination, $thread);
                $conversations += $isNewConv ? 1 : 0;
                $messages += $newMessages;
            }
        }

        return ['conversations' => $conversations, 'messages' => $messages];
    }

    public function syncDue(): int
    {
        $dispatched = 0;

        SocialConnectionDestination::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas('connection', fn ($q) => $q->where('status', ConnectionStatus::CONNECTED->value))
            ->pluck('id')
            ->each(function (int $id) use (&$dispatched): void {
                SyncInboxConversations::dispatch($id);
                $dispatched++;
            });

        return $dispatched;
    }

    public function syncDestination(SocialConnectionDestination $destination): void
    {
        $connection = SocialConnection::query()->withoutGlobalScopes()->find($destination->social_connection_id);
        if ($connection === null || $connection->status !== ConnectionStatus::CONNECTED) {
            return;
        }

        $adapter = $this->manager->adapter($connection->provider);
        if ($adapter === null) {
            return;
        }

        $credentials = $this->manager->record($connection->provider)?->credentialMap() ?? [];

        try {
            $threads = $adapter->fetchConversations($connection->toTokens(), $destination->external_id, $credentials);
        } catch (ProviderNotConfiguredException) {
            return;
        }

        foreach ($threads as $thread) {
            $this->storeThread($connection, $destination, $thread);
        }
    }

    public function reply(InboxConversation $conversation, User $user, string $body): InboxMessage
    {
        $connection = SocialConnection::query()->withoutGlobalScopes()->findOrFail($conversation->social_connection_id);
        $adapter = $this->manager->adapter($connection->provider);
        if ($adapter === null) {
            throw new ProviderNotConfiguredException('Proveedor no disponible para responder.');
        }

        $credentials = $this->manager->record($connection->provider)?->credentialMap() ?? [];
        $result = $adapter->replyToConversation($connection->toTokens(), $conversation->external_id, $body, $credentials);

        $message = InboxMessage::query()->create([
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'external_id' => $result->externalId,
            'type' => 'reply',
            'author_name' => $user->name,
            'body' => $body,
            'via_user_id' => $user->id,
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now(), 'unread_count' => 0]);

        return $message;
    }

    public function addNote(InboxConversation $conversation, User $user, string $body): InboxMessage
    {
        return InboxMessage::query()->create([
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'type' => 'note',
            'author_name' => $user->name,
            'body' => $body,
            'via_user_id' => $user->id,
            'sent_at' => now(),
        ]);
    }

    /**
     * @return array{0: bool, 1: int}  [conversación nueva, mensajes nuevos]
     */
    private function storeThread(
        SocialConnection $connection,
        SocialConnectionDestination $destination,
        InboxThread $thread,
    ): array {
        $conversation = InboxConversation::query()->withoutGlobalScopes()->firstOrNew([
            'social_connection_id' => $connection->id,
            'external_id' => $thread->externalId,
        ]);
        $isNew = ! $conversation->exists;

        if ($isNew) {
            $conversation->organization_id = $connection->organization_id;
            $conversation->brand_id = $connection->brand_id;
            $conversation->provider = $connection->provider;
            $conversation->type = $thread->type;
            $conversation->status = ConversationStatus::OPEN;
            $conversation->participant_external_id = $thread->participantExternalId;
        }

        $conversation->social_connection_destination_id = $destination->id;
        $conversation->participant_name = $thread->participantName;
        $conversation->last_message_at = $thread->lastMessageAt;
        $lastMessage = $thread->messages[array_key_last($thread->messages)] ?? null;
        if ($lastMessage !== null) {
            $conversation->preview = Str::limit($lastMessage->body, 160);
        }
        $conversation->save();

        $newMessages = 0;
        foreach ($thread->messages as $msg) {
            $created = InboxMessage::query()->withoutGlobalScopes()->firstOrCreate(
                ['conversation_id' => $conversation->id, 'external_id' => $msg->externalId],
                [
                    'organization_id' => $connection->organization_id,
                    'type' => $msg->direction === 'outbound' ? 'reply' : 'inbound',
                    'author_name' => $msg->authorName,
                    'author_external_id' => $msg->authorExternalId,
                    'body' => $msg->body,
                    'sent_at' => $msg->sentAt,
                ],
            );
            if ($created->wasRecentlyCreated) {
                $newMessages++;
                if ($msg->direction !== 'outbound') {
                    $conversation->increment('unread_count');
                }
            }
        }

        return [$isNew, $newMessages];
    }

    /**
     * @return \Illuminate\Support\Collection<int, SocialConnectionDestination>
     */
    private function brandDestinations(Brand $brand): \Illuminate\Support\Collection
    {
        $connectionIds = SocialConnection::query()->withoutGlobalScopes()
            ->where('brand_id', $brand->id)
            ->where('status', ConnectionStatus::CONNECTED->value)
            ->pluck('id');

        return SocialConnectionDestination::query()->withoutGlobalScopes()
            ->whereIn('social_connection_id', $connectionIds)
            ->where('is_active', true)
            ->get();
    }
}
