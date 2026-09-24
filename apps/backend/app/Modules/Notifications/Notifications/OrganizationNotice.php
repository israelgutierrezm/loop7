<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Notifications\Channels\OrganizationDatabaseChannel;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Services\NotificationPreferences;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Aviso para un miembro dentro de una Organization. Se guarda en la app
 * (campana) y, si la categoría lo permite y el usuario no lo desactivó, se
 * envía por correo. El texto se compone al crearlo: no depende de que el
 * recurso siga existiendo cuando la cola lo procese.
 */
class OrganizationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const LEVELS = ['info', 'success', 'warning', 'danger'];

    /**
     * @param  string  $kind  identificador del tipo (p. ej. "content.submitted")
     * @param  string|null  $path  ruta del SPA a la que lleva (p. ej. "/app/content/01H…")
     * @param  string  $level  info | success | warning | danger
     * @param  bool  $mailable  si tiene sentido enviarlo también por correo
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

        if ($this->mailable
            && $notifiable instanceof User
            && app(NotificationPreferences::class)->mailEnabled($notifiable, $this->category)
        ) {
            $channels[] = 'mail';
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

        $mail = (new MailMessage())
            ->subject($this->title)
            ->greeting($firstName !== '' ? "Hola, {$firstName}:" : 'Hola:')
            ->line($this->body);

        if ($this->level === 'danger') {
            $mail->error();
        }

        if ($this->path !== null) {
            $mail->action('Abrir en ' . config('app.name'), $this->url($organization));
        }

        if ($organization !== null) {
            $mail->line('Organización: ' . $organization->name . '.');
        }

        return $mail->line('Puedes elegir qué avisos recibir por correo en Mi perfil → Notificaciones.');
    }

    /**
     * Enlace al SPA que además selecciona la Organization del aviso.
     */
    private function url(?Organization $organization): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/') . $this->path;

        if ($organization === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'org=' . $organization->public_id;
    }
}
