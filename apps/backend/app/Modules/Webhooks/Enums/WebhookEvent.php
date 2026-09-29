<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Eventos a los que se puede suscribir un endpoint (docs/11). `webhook.test`
 * no es suscribible: sólo lo envía el botón «Enviar prueba».
 */
enum WebhookEvent: string
{
    case CONTENT_SUBMITTED = 'content.submitted';
    case CONTENT_APPROVED = 'content.approved';
    case CONTENT_CHANGES_REQUESTED = 'content.changes_requested';
    case CONTENT_PUBLISHED = 'content.published';
    case CONTENT_FAILED = 'content.failed';
    case PUBLICATION_DELETED = 'publication.deleted';
    case INBOX_MESSAGE_RECEIVED = 'inbox.message_received';
    case SOCIAL_CONNECTION_EXPIRED = 'social.connection_expired';
    case TEST = 'webhook.test';

    public function label(): string
    {
        return match ($this) {
            self::CONTENT_SUBMITTED => 'Contenido enviado a revisión',
            self::CONTENT_APPROVED => 'Contenido aprobado',
            self::CONTENT_CHANGES_REQUESTED => 'Se pidieron cambios a un contenido',
            self::CONTENT_PUBLISHED => 'Contenido publicado (en todas o en algunas redes)',
            self::CONTENT_FAILED => 'La publicación falló en todas las redes',
            self::PUBLICATION_DELETED => 'Una publicación se borró de su red desde Loop7',
            self::INBOX_MESSAGE_RECEIVED => 'Mensaje nuevo en el inbox',
            self::SOCIAL_CONNECTION_EXPIRED => 'Una cuenta social caducó y hay que reconectarla',
            self::TEST => 'Prueba',
        };
    }

    /**
     * @return list<self>
     */
    public static function subscribable(): array
    {
        $events = [];
        foreach (self::cases() as $event) {
            if ($event !== self::TEST) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    public static function subscribableValues(): array
    {
        return array_map(fn (self $e) => $e->value, self::subscribable());
    }
}
