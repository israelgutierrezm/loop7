<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Billing\Events\SubscriptionChanged;
use App\Modules\Billing\Events\TrialEndingSoon;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Events\ContentPublicationFailed;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Content\Events\ContentReviewed;
use App\Modules\Content\Events\ContentSubmittedForReview;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Inbox\Events\ConversationAssigned;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Organizations\Models\Organization;
use App\Modules\PlatformAdmin\Services\PlatformSettings;
use App\Modules\SocialConnections\Events\SocialConnectionExpired;
use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Traduce eventos de dominio de otros módulos en avisos para las personas
 * adecuadas, sin que esos módulos conozcan a Notifications.
 */
class SendDomainNotifications
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly PlatformSettings $settings,
    ) {
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
            SocialConnectionExpired::class => 'onSocialConnectionExpired',
            SubscriptionChanged::class => 'onSubscriptionChanged',
            TrialEndingSoon::class => 'onTrialEndingSoon',
            ConversationAssigned::class => 'onConversationAssigned',
        ];
    }

    public function onContentSubmitted(ContentSubmittedForReview $event): void
    {
        $content = $event->content;

        $this->notifier->toMembersWithPermission(
            Permission::CONTENT_APPROVE,
            $this->contentNotice(
                $content,
                'content.submitted',
                NotificationCategory::APPROVALS,
                'Contenido pendiente de aprobación',
                "{$event->submittedBy->name} envió «{$content->title}» a revisión.",
            ),
            brandId: $content->brand_id,
            except: [$event->submittedBy->id],
        );
    }

    public function onContentReviewed(ContentReviewed $event): void
    {
        $content = $event->content;
        $reviewer = $event->reviewer->name;

        $notice = $event->approved
            ? $this->contentNotice(
                $content,
                'content.approved',
                NotificationCategory::APPROVALS,
                'Contenido aprobado',
                "{$reviewer} aprobó «{$content->title}». Ya se puede programar o publicar.",
                'success',
            )
            : $this->contentNotice(
                $content,
                'content.changes_requested',
                NotificationCategory::APPROVALS,
                'Se solicitaron cambios',
                "{$reviewer} pidió cambios en «{$content->title}»: " . Str::limit((string) $event->note, 300),
                'warning',
            );

        $this->notifier->toUsers(
            [$content->created_by_user_id, $event->submittedByUserId],
            $notice,
            brandId: $content->brand_id,
            except: [$event->reviewer->id],
        );
    }

    public function onContentPublished(ContentPublished $event): void
    {
        $content = $event->content;
        $partial = $event->status === ContentStatus::PARTIAL->value;

        $notice = $partial
            ? $this->contentNotice(
                $content,
                'content.partially_published',
                NotificationCategory::PUBLISHING,
                'Publicación con errores',
                "«{$content->title}» se publicó sólo en algunas redes. Revisa qué falló y vuelve a intentarlo.",
                'warning',
            )
            // Un éxito se ve en la app pero no merece un correo.
            : $this->contentNotice(
                $content,
                'content.published',
                NotificationCategory::PUBLISHING,
                'Contenido publicado',
                "«{$content->title}» se publicó en todas sus redes.",
                'success',
                mailable: false,
            );

        $this->notifyContentOwners($content, $notice);
    }

    public function onContentPublicationFailed(ContentPublicationFailed $event): void
    {
        $content = $event->content;

        $this->notifyContentOwners($content, $this->contentNotice(
            $content,
            'content.publish_failed',
            NotificationCategory::PUBLISHING,
            'No se pudo publicar',
            "«{$content->title}» no se publicó en ninguna red. Revisa el detalle del error y vuelve a intentarlo.",
            'danger',
        ));
    }

    public function onSocialConnectionExpired(SocialConnectionExpired $event): void
    {
        $connection = $event->connection;
        $provider = SocialProvider::query()->where('key', $connection->provider)->value('name')
            ?? Str::headline($connection->provider);
        $brand = Brand::query()->withoutGlobalScopes()->whereKey($connection->brand_id)->value('name');
        $account = $connection->external_account_name ?? $provider;

        $this->notifier->toMembersWithPermission(
            Permission::SOCIAL_ACCOUNTS_RECONNECT,
            new OrganizationNotice(
                organizationId: $connection->organization_id,
                kind: 'social.connection_expired',
                category: NotificationCategory::SOCIAL,
                title: "Reconecta tu cuenta de {$provider}",
                body: "La conexión «{$account}»" . ($brand !== null ? " de la marca {$brand}" : '')
                    . ' caducó o fue revocada. Reconéctala para seguir publicando.',
                path: '/app/social',
                level: 'warning',
            ),
            brandId: $connection->brand_id,
        );
    }

    public function onSubscriptionChanged(SubscriptionChanged $event): void
    {
        $notice = $this->subscriptionNotice($event);

        if ($notice !== null) {
            $this->notifier->toMembersWithPermission(Permission::BILLING_VIEW, $notice);
        }
    }

    public function onTrialEndingSoon(TrialEndingSoon $event): void
    {
        $subscription = $event->subscription;

        $this->notifier->toMembersWithPermission(Permission::BILLING_VIEW, $this->billingNotice(
            $subscription,
            'billing.trial_ending',
            'Tu periodo de prueba termina pronto',
            'Tu prueba termina el ' . $this->date($subscription->trial_ends_at, $subscription)
                . '. Elige un plan para no perder la publicación ni la IA.',
            'warning',
        ));
    }

    public function onConversationAssigned(ConversationAssigned $event): void
    {
        $conversation = $event->conversation;
        $brand = Brand::query()->withoutGlobalScopes()->whereKey($conversation->brand_id)->value('public_id');
        $participant = $conversation->participant_name ?? 'un usuario';

        $this->notifier->toUsers(
            [$event->assignee->id],
            new OrganizationNotice(
                organizationId: $conversation->organization_id,
                kind: 'inbox.assigned',
                category: NotificationCategory::INBOX,
                title: 'Te asignaron una conversación',
                body: "{$event->assignedBy->name} te asignó la conversación con {$participant} ("
                    . Str::headline($conversation->provider) . ').',
                path: '/app/inbox?' . http_build_query(['brand' => $brand, 'conversation' => $conversation->public_id]),
            ),
            brandId: $conversation->brand_id,
            except: [$event->assignedBy->id],
        );
    }

    private function subscriptionNotice(SubscriptionChanged $event): ?OrganizationNotice
    {
        $s = $event->subscription;
        $plan = $s->plan->name ?? 'tu plan';
        $until = $this->date($s->current_period_end, $s);
        $grace = max(0, $this->settings->int('billing.grace_days'));

        return match ($event->action) {
            AuditAction::SUBSCRIPTION_CHANGED => isset($event->properties['trial_extended_days'])
                ? $this->billingNotice($s, 'billing.trial_extended', 'Periodo de prueba ampliado', "Tu prueba se amplió hasta el {$until}.", 'success')
                : $this->billingNotice($s, 'billing.plan_activated', "Plan {$plan} activo", "Tu suscripción al plan {$plan} está activa hasta el {$until}.", 'success'),
            AuditAction::SUBSCRIPTION_RENEWED => $this->billingNotice(
                $s,
                'billing.renewed',
                'Suscripción renovada',
                "Recibimos tu pago: el plan {$plan} sigue activo hasta el {$until}.",
                'success',
                mailable: false,
            ),
            AuditAction::SUBSCRIPTION_PAYMENT_FAILED => $this->billingNotice(
                $s,
                'billing.payment_failed',
                'No recibimos tu pago',
                "No pudimos cobrar la renovación del plan {$plan}. Tienes {$grace} día(s) para regularizarlo antes de que se suspenda la cuenta.",
                'danger',
            ),
            AuditAction::SUBSCRIPTION_SUSPENDED => $this->billingNotice(
                $s,
                'billing.suspended',
                'Suscripción suspendida',
                'La publicación, la IA y las integraciones están en pausa hasta que regularices el pago. Tus datos se conservan.',
                'danger',
            ),
            AuditAction::SUBSCRIPTION_EXPIRED => $this->billingNotice(
                $s,
                'billing.trial_expired',
                'Tu periodo de prueba terminó',
                'Elige un plan para seguir publicando y usando la IA. Tus datos se conservan.',
                'warning',
            ),
            AuditAction::SUBSCRIPTION_CANCELLED => ($event->properties['at_period_end'] ?? false) === true
                ? $this->billingNotice($s, 'billing.cancel_scheduled', 'Cancelación programada', "Tu suscripción se cancelará el {$until}. Puedes reanudarla antes desde Facturación.")
                : $this->billingNotice($s, 'billing.cancelled', 'Suscripción cancelada', 'Tu suscripción terminó. Puedes elegir un plan cuando quieras volver.', 'warning'),
            AuditAction::SUBSCRIPTION_RESUMED => $this->billingNotice(
                $s,
                'billing.resumed',
                'Suscripción reanudada',
                'Se anuló la cancelación programada: tu plan seguirá renovándose.',
                'success',
                mailable: false,
            ),
            default => null,
        };
    }

    /**
     * Autor del contenido y quien lo aprobó: son quienes deben enterarse del
     * resultado de la publicación.
     */
    private function notifyContentOwners(ContentItem $content, OrganizationNotice $notice): void
    {
        $this->notifier->toUsers(
            [$content->created_by_user_id, $content->approved_by_user_id],
            $notice,
            brandId: $content->brand_id,
        );
    }

    private function contentNotice(
        ContentItem $content,
        string $kind,
        NotificationCategory $category,
        string $title,
        string $body,
        string $level = 'info',
        bool $mailable = true,
    ): OrganizationNotice {
        return new OrganizationNotice(
            organizationId: $content->organization_id,
            kind: $kind,
            category: $category,
            title: $title,
            body: $body,
            path: "/app/content/{$content->public_id}",
            level: $level,
            mailable: $mailable,
        );
    }

    private function billingNotice(
        Subscription $subscription,
        string $kind,
        string $title,
        string $body,
        string $level = 'info',
        bool $mailable = true,
    ): OrganizationNotice {
        return new OrganizationNotice(
            organizationId: $subscription->organization_id,
            kind: $kind,
            category: NotificationCategory::BILLING,
            title: $title,
            body: $body,
            path: '/app/billing',
            level: $level,
            mailable: $mailable,
        );
    }

    /**
     * Fecha legible en la zona horaria de la Organization.
     */
    private function date(?Carbon $date, Subscription $subscription): string
    {
        if ($date === null) {
            return '—';
        }

        $timezone = Organization::query()->whereKey($subscription->organization_id)->value('timezone') ?: 'UTC';

        return $date->copy()->setTimezone($timezone)->format('d/m/Y');
    }
}
