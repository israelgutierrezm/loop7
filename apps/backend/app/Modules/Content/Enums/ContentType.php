<?php

declare(strict_types=1);

namespace App\Modules\Content\Enums;

enum ContentType: string
{
    case POST = 'post';
    case THREAD = 'thread';
    case STORY = 'story';
    case REEL = 'reel';
    case CAROUSEL = 'carousel';
    case VIDEO = 'video';
    case DOCUMENT = 'document';
    case LINK = 'link';

    public function label(): string
    {
        return match ($this) {
            self::POST => 'Publicación',
            self::THREAD => 'Hilo',
            self::STORY => 'Historia',
            self::REEL => 'Reel',
            self::CAROUSEL => 'Carrusel',
            self::VIDEO => 'Video',
            self::DOCUMENT => 'Documento',
            self::LINK => 'Enlace',
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
