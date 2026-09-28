<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Tipos de acción que una automatización puede ejecutar. Reutilizan módulos
 * existentes (Notifications, Inbox, Content) o realizan efectos externos
 * (webhook saliente).
 */
enum AutomationActionType: string
{
    case NOTIFY = 'notify';
    case WEBHOOK = 'webhook';
    case CREATE_DRAFT = 'create_draft';
    case INBOX_REPLY = 'inbox_reply';
    case INBOX_TAG = 'inbox_tag';

    public function label(): string
    {
        return match ($this) {
            self::NOTIFY => 'Avisar al equipo',
            self::WEBHOOK => 'Llamar webhook saliente',
            self::CREATE_DRAFT => 'Crear borrador de contenido',
            self::INBOX_REPLY => 'Responder en el inbox',
            self::INBOX_TAG => 'Etiquetar conversación',
        };
    }

    /**
     * Disparadores con los que tiene sentido (null = todos): las acciones del
     * inbox necesitan una conversación.
     *
     * @return list<AutomationTrigger>|null
     */
    public function triggers(): ?array
    {
        return match ($this) {
            self::INBOX_REPLY, self::INBOX_TAG => [AutomationTrigger::INBOX_MESSAGE_RECEIVED],
            default => null,
        };
    }

    public function allowsTrigger(AutomationTrigger $trigger): bool
    {
        $triggers = $this->triggers();

        return $triggers === null || in_array($trigger, $triggers, true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
