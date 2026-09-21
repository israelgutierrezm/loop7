<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * Modalidades de generación de IA (docs/07). Video/embeddings quedan previstos
 * pero fuera del alcance de la Fase 7 (texto + imagen).
 */
enum AiModality: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case VIDEO = 'video';

    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Texto',
            self::IMAGE => 'Imagen',
            self::VIDEO => 'Video',
        };
    }
}
