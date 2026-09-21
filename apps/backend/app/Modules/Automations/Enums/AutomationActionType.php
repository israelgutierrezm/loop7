<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Tipos de acción que una automatización puede ejecutar. Reutilizan módulos
 * existentes (Inbox) o realizan efectos externos (webhook saliente).
 */
enum AutomationActionType: string
{
    case NOTIFY = 'notify';
    case WEBHOOK = 'webhook';
    case INBOX_REPLY = 'inbox_reply';
    case INBOX_TAG = 'inbox_tag';

    public function label(): string
    {
        return match ($this) {
            self::NOTIFY => 'Registrar notificación',
            self::WEBHOOK => 'Llamar webhook saliente',
            self::INBOX_REPLY => 'Responder en el inbox',
            self::INBOX_TAG => 'Etiquetar conversación',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
