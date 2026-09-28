<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

/**
 * Disparadores de automatización (docs/05): eventos internos de otros módulos
 * y disparadores externos (webhook entrante y feed RSS/Atom).
 */
enum AutomationTrigger: string
{
    case CONTENT_PUBLISHED = 'content.published';
    case INBOX_MESSAGE_RECEIVED = 'inbox.message_received';
    case WEBHOOK_RECEIVED = 'webhook.received';
    case RSS_ITEM_PUBLISHED = 'rss.item_published';

    public function label(): string
    {
        return match ($this) {
            self::CONTENT_PUBLISHED => 'Cuando se publica contenido',
            self::INBOX_MESSAGE_RECEIVED => 'Cuando llega un mensaje al inbox',
            self::WEBHOOK_RECEIVED => 'Cuando llega un webhook entrante',
            self::RSS_ITEM_PUBLISHED => 'Cuando hay una entrada nueva en un feed RSS',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CONTENT_PUBLISHED => 'Se ejecuta al terminar de publicar un contenido (en todas o en algunas redes).',
            self::INBOX_MESSAGE_RECEIVED => 'Se ejecuta con cada comentario o mensaje nuevo que llega al inbox.',
            self::WEBHOOK_RECEIVED => 'Otra herramienta (Zapier, Make, tu web…) hace un POST a una URL secreta. Los campos del JSON recibido se usan como variables: {titulo}, {cliente.nombre}…',
            self::RSS_ITEM_PUBLISHED => 'Se revisa el feed cada 15 minutos; cada entrada nueva ejecuta las acciones (las que ya existían al crear la regla no cuentan).',
        };
    }

    /**
     * Campos de contexto disponibles para condiciones y variables (para la UI).
     * El webhook entrante no tiene campos fijos: son los del JSON recibido.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::CONTENT_PUBLISHED => ['content_title', 'content_status', 'brand'],
            self::INBOX_MESSAGE_RECEIVED => ['provider', 'type', 'participant', 'text'],
            self::WEBHOOK_RECEIVED => [],
            self::RSS_ITEM_PUBLISHED => ['title', 'link', 'summary', 'author', 'published_at', 'image', 'feed_title'],
        };
    }

    /**
     * ¿Llega desde fuera de Loop7 (sin marca de evento)?
     */
    public function isExternal(): bool
    {
        return $this === self::WEBHOOK_RECEIVED || $this === self::RSS_ITEM_PUBLISHED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
