<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Listeners;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Content\Events\ContentPublicationFailed;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Content\Events\ContentReviewed;
use App\Modules\Content\Events\ContentSubmittedForReview;
use App\Modules\Content\Events\PublicationDeletedRemotely;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Inbox\Events\InboxMessageReceived;
use App\Modules\SocialConnections\Events\SocialConnectionExpired;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\Webhooks\Enums\WebhookEvent;
use App\Modules\Webhooks\Services\WebhookDispatcher;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Events\Dispatcher;

/**
 * Traduce eventos de dominio en mensajes de webhook (docs/11), sin que los
 * módulos que los emiten conozcan a Webhooks. Los datos usan sólo
 * identificadores públicos y nunca incluyen tokens ni datos internos.
 */
class SendDomainWebhooks
{
    public function __construct(private readonly WebhookDispatcher $webhooks)
    {
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ContentSubmittedForReview::class => 'onContentSubmitted',
            ContentReviewed::class => 'onContentReviewed',
            ContentPublished::class => 'onContentPublished',
            ContentPublicationFailed::class => 'onContentPublicationFailed',
            PublicationDeletedRemotely::class => 'onPublicationDeleted',
            InboxMessageReceived::class => 'onInboxMessageReceived',
            SocialConnectionExpired::class => 'onSocialConnectionExpired',
        ];
    }

    public function onContentSubmitted(ContentSubmittedForReview $event): void
    {
        $this->webhooks->dispatch($event->content->organization_id, WebhookEvent::CONTENT_SUBMITTED, [
            'content' => $this->content($event->content),
            'submitted_by' => $this->person($event->submittedBy),
        ]);
    }

    public function onContentReviewed(ContentReviewed $event): void
    {
        $this->webhooks->dispatch(
            $event->content->organization_id,
            $event->approved ? WebhookEvent::CONTENT_APPROVED : WebhookEvent::CONTENT_CHANGES_REQUESTED,
            [
                'content' => $this->content($event->content),
                'reviewer' => $this->person($event->reviewer),
                'note' => $event->note,
            ],
        );
    }

    public function onContentPublished(ContentPublished $event): void
    {
        $this->webhooks->dispatch($event->content->organization_id, WebhookEvent::CONTENT_PUBLISHED, [
            'content' => $this->content($event->content),
            'targets' => $this->targets($event->content),
        ]);
    }

    public function onContentPublicationFailed(ContentPublicationFailed $event): void
    {
        $this->webhooks->dispatch($event->content->organization_id, WebhookEvent::CONTENT_FAILED, [
            'content' => $this->content($event->content),
            'targets' => $this->targets($event->content),
        ]);
    }

    public function onPublicationDeleted(PublicationDeletedRemotely $event): void
    {
        $target = $event->target;
        $destination = $target->social_connection_destination_id !== null
            ? SocialConnectionDestination::query()->withoutGlobalScope(OrganizationScope::class)
                ->find($target->social_connection_destination_id, ['id', 'name'])
            : null;

        $this->webhooks->dispatch($event->content->organization_id, WebhookEvent::PUBLICATION_DELETED, [
            'content' => $this->content($event->content),
            'publication' => [
                'id' => $target->public_id,
                'provider' => $event->provider,
                'destination' => $destination?->name,
                'published_at' => $target->published_at?->toIso8601String(),
                'deleted_at' => $target->remote_deleted_at?->toIso8601String(),
            ],
        ]);
    }

    public function onInboxMessageReceived(InboxMessageReceived $event): void
    {
        $conversation = $event->conversation;
        $message = $event->message;

        $this->webhooks->dispatch($conversation->organization_id, WebhookEvent::INBOX_MESSAGE_RECEIVED, [
            'brand' => $this->brand($conversation->brand_id),
            'conversation' => [
                'id' => $conversation->public_id,
                'provider' => $conversation->provider,
                'type' => $conversation->type,
                'participant' => $conversation->participant_name,
                'status' => $conversation->status->value,
                'url' => $this->appUrl('/app/inbox?' . http_build_query([
                    'brand' => Brand::query()->withoutGlobalScope(OrganizationScope::class)->whereKey($conversation->brand_id)->value('public_id'),
                    'conversation' => $conversation->public_id,
                ])),
            ],
            'message' => [
                'id' => $message->public_id,
                'author' => $message->author_name,
                'text' => $message->body,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ],
        ]);
    }

    public function onSocialConnectionExpired(SocialConnectionExpired $event): void
    {
        $connection = $event->connection;

        $this->webhooks->dispatch($connection->organization_id, WebhookEvent::SOCIAL_CONNECTION_EXPIRED, [
            'brand' => $this->brand($connection->brand_id),
            'connection' => [
                'id' => $connection->public_id,
                'provider' => $connection->provider,
                'account' => $connection->external_account_name,
                'status' => $connection->status->value,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(ContentItem $content): array
    {
        $campaign = $content->campaign_id !== null
            ? Campaign::query()->withoutGlobalScope(OrganizationScope::class)->find($content->campaign_id)
            : null;

        return [
            'id' => $content->public_id,
            'title' => $content->title,
            'type' => $content->type->value,
            'status' => $content->status->value,
            'scheduled_at' => $content->scheduled_at?->toIso8601String(),
            'url' => $this->appUrl('/app/content/' . $content->public_id),
            'brand' => $this->brand($content->brand_id),
            'campaign' => $campaign !== null ? ['id' => $campaign->public_id, 'name' => $campaign->name] : null,
        ];
    }

    /**
     * Resultado por red/destino de una publicación.
     *
     * @return list<array<string, mixed>>
     */
    private function targets(ContentItem $content): array
    {
        return PublicationTarget::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('post_variant_id', PostVariant::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('content_item_id', $content->id)
                ->select('id'))
            ->with([
                'variant' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
                'destination' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (PublicationTarget $target): array => [
                'id' => $target->public_id,
                'provider' => $target->variant?->provider,
                'destination' => $target->destination?->name,
                'status' => $target->status->value,
                'url' => $target->remote_url,
                'published_at' => $target->published_at?->toIso8601String(),
                'error' => $target->error,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function brand(int $brandId): ?array
    {
        $brand = Brand::query()->withoutGlobalScope(OrganizationScope::class)->find($brandId, ['id', 'public_id', 'name']);

        return $brand !== null ? ['id' => $brand->public_id, 'name' => $brand->name] : null;
    }

    /**
     * @return array{id: string, name: string}
     */
    private function person(User $user): array
    {
        return ['id' => $user->public_id, 'name' => $user->name];
    }

    private function appUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }
}
