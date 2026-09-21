<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Disparadores de automatización basados en eventos internos (docs/05).
 * RSS/webhooks entrantes quedan previstos para más adelante.
 */
enum AutomationTrigger: string
{
    case CONTENT_PUBLISHED = 'content.published';
    case INBOX_MESSAGE_RECEIVED = 'inbox.message_received';

    public function label(): string
    {
        return match ($this) {
            self::CONTENT_PUBLISHED => 'Cuando se publica contenido',
            self::INBOX_MESSAGE_RECEIVED => 'Cuando llega un mensaje al inbox',
        };
    }

    /**
     * Campos de contexto disponibles para condiciones (para la UI).
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::CONTENT_PUBLISHED => ['content_title', 'content_status', 'brand'],
            self::INBOX_MESSAGE_RECEIVED => ['provider', 'type', 'participant', 'text'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
