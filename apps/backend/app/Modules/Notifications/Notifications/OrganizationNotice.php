<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Notifications\Channels\OrganizationDatabaseChannel;
use App\Modules\Notifications\Channels\WebPushChannel;
use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\Enums\DeliveryChannel;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Services\NotificationChannels;
use App\Modules\Notifications\Services\NotificationPreferences;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\OrganizationBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Aviso para un miembro dentro de una Organization. Se guarda en la app
 * (campana) y, según las preferencias del usuario por categoría, sale además
 * por correo, push del navegador o WhatsApp. El texto se compone al crearlo:
 * no depende de que el recurso siga existiendo cuando la cola lo procese.
 */
class OrganizationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const LEVELS = ['info', 'success', 'warning', 'danger'];

    /**
     * @param  string  $kind  identificador del tipo (p. ej. "content.submitted")
     * @param  string|null  $path  ruta del SPA a la que lleva (p. ej. "/app/content/01H…")
     * @param  string  $level  info | success | warning | danger
     * @param  bool  $mailable  si tiene sentido enviarlo fuera de la app (correo, push, WhatsApp)
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $kind,
        public readonly NotificationCategory $category,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $path = null,
        public readonly string $level = 'info',
        public readonly bool $mailable = true,
    ) {
        // Si la operación que lo origina se revierte, el aviso no sale.
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = [OrganizationDatabaseChannel::class];
        if (! $this->mailable || ! $notifiable instanceof User) {
            return $channels;
        }

        $preferences = app(NotificationPreferences::class);
        $available = app(NotificationChannels::class);

        if ($preferences->enabled($notifiable, DeliveryChannel::MAIL, $this->category)) {
            $channels[] = 'mail';
        }
        if ($preferences->enabled($notifiable, DeliveryChannel::PUSH, $this->category)
            && $available->pushReady()
            && $notifiable->pushSubscriptions()->exists()
        ) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->whatsapp_verified_at !== null
            && $preferences->enabled($notifiable, DeliveryChannel::WHATSAPP, $this->category)
            && $available->whatsAppAllowedFor(Organization::query()->find($this->organizationId))
        ) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category->value,
            'level' => $this->level,
            'title' => $this->title,
            'body' => $this->body,
            'path' => $this->path,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::query()->find($this->organizationId);
        $firstName = Str::before(trim((string) ($notifiable->name ?? '')), ' ');
        // Con marca blanca, el correo se presenta con el nombre de la organización.
        $product = $organization !== null
            ? app(OrganizationBranding::class)->productName($organization)
            : (string) config('app.name');

        $mail = (new MailMessage())
            ->from((string) config('mail.from.address'), $product)
            ->subject($this->title)
            ->greeting($firstName !== '' ? "Hola, {$firstName}:" : 'Hola:')
            ->line($this->body)
            ->salutation("— {$product}");

        if ($this->level === 'danger') {
            $mail->error();
        }

        if ($this->path !== null) {
            $mail->action("Abrir en {$product}", $this->url($organization));
        }

        if ($organization !== null) {
            $mail->line('Organización: ' . $organization->name . '.');
        }

        return $mail->line('Puedes elegir qué avisos recibir y por dónde en Mi perfil → Notificaciones.');
    }

    /**
     * Lo que muestra el service worker del navegador (public/sw.js).
     *
     * @return array{title: string, body: string, path: string|null, tag: string}
     */
    public function toWebPush(object $notifiable): array
    {
        $organization = Organization::query()->find($this->organizationId);

        return [
            'title' => $this->title,
            'body' => $organization !== null ? "{$this->body} · {$organization->name}" : $this->body,
            'path' => $this->path !== null ? $this->withOrganization($this->path, $organization) : null,
            // Avisos del mismo tipo se reemplazan en lugar de apilarse.
            'tag' => $this->kind,
        ];
    }

    /**
     * Variables de la plantilla de avisos de WhatsApp.
     *
     * @return array{organization: string, title: string, body: string}
     */
    public function toWhatsApp(object $notifiable): array
    {
        $organization = Organization::query()->find($this->organizationId);

        return [
            'organization' => $organization !== null ? $organization->name : (string) config('app.name'),
            'title' => $this->title,
            'body' => $this->body,
        ];
    }

    /**
     * Enlace al SPA que además selecciona la Organization del aviso.
     */
    private function url(?Organization $organization): string
    {
        return $this->withOrganization(rtrim((string) config('app.frontend_url'), '/') . $this->path, $organization);
    }

    private function withOrganization(string $url, ?Organization $organization): string
    {
        if ($organization === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'org=' . $organization->public_id;
    }
}
