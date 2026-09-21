<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Notifications;

use App\Modules\Organizations\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OrganizationInvitation $invitation,
        private readonly string $plainToken,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $url = $frontend . '/aceptar-invitacion?token=' . $this->plainToken;
        $organizationName = $this->invitation->organization->name;

        return (new MailMessage())
            ->subject('Te invitaron a ' . $organizationName)
            ->greeting('¡Hola!')
            ->line('Has sido invitado a colaborar en "' . $organizationName . '".')
            ->action('Aceptar invitación', $url)
            ->line('Esta invitación expira el ' . $this->invitation->expires_at?->format('d/m/Y H:i') . '.')
            ->line('Si no esperabas esta invitación, puedes ignorar este correo.');
    }
}
