<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * Modalidades de IA (docs/07): texto, imagen y embeddings (búsqueda semántica
 * del Brand Brain). Video queda previsto.
 */
enum AiModality: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case VIDEO = 'video';
    case EMBEDDING = 'embedding';

    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Texto',
            self::IMAGE => 'Imagen',
            self::VIDEO => 'Video',
            self::EMBEDDING => 'Embeddings',
        };
    }
}
