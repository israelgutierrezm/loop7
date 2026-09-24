<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * Categorías de aviso. En la app se muestran siempre; cada usuario decide
 * cuáles recibe además por correo.
 */
enum NotificationCategory: string
{
    case APPROVALS = 'approvals';
    case PUBLISHING = 'publishing';
    case SOCIAL = 'social';
    case BILLING = 'billing';
    case INBOX = 'inbox';
    case AUTOMATIONS = 'automations';

    public function label(): string
    {
        return match ($this) {
            self::APPROVALS => 'Revisión y aprobación',
            self::PUBLISHING => 'Publicaciones con errores',
            self::SOCIAL => 'Cuentas sociales',
            self::BILLING => 'Suscripción y pagos',
            self::INBOX => 'Conversaciones asignadas',
            self::AUTOMATIONS => 'Automatizaciones',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::APPROVALS => 'Contenido que espera tu aprobación y la respuesta a lo que enviaste a revisión.',
            self::PUBLISHING => 'Publicaciones que fallaron en todas o en algunas redes.',
            self::SOCIAL => 'Conexiones que caducaron y hay que reconectar para seguir publicando.',
            self::BILLING => 'Fin de la prueba, pagos no recibidos, suspensión y cambios de plan.',
            self::INBOX => 'Cuando alguien te asigna una conversación.',
            self::AUTOMATIONS => 'Avisos que envían tus automatizaciones.',
        };
    }

    /**
     * ¿Se envía por correo si el usuario no eligió otra cosa?
     */
    public function mailByDefault(): bool
    {
        return match ($this) {
            self::INBOX, self::AUTOMATIONS => false,
            default => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
