<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * Canales por los que un aviso sale de la app. En la app (campana) se muestran
 * siempre; cada usuario elige por categoría qué recibe además por cada canal.
 */
enum DeliveryChannel: string
{
    case MAIL = 'mail';
    case PUSH = 'push';
    case WHATSAPP = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::MAIL => 'Correo',
            self::PUSH => 'Push',
            self::WHATSAPP => 'WhatsApp',
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
