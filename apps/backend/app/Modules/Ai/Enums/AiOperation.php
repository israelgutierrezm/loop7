<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * Operaciones de IA que consumen créditos. El coste por defecto puede ser
 * sobrescrito por SUPERADMIN en la configuración del proveedor (docs/07).
 */
enum AiOperation: string
{
    case GENERATE_POST = 'generate_post';
    case GENERATE_IDEAS = 'generate_ideas';
    case IMPROVE_TEXT = 'improve_text';
    case ADAPT_VARIANT = 'adapt_variant';
    case SUGGEST_REPLY = 'suggest_reply';
    case GENERATE_IMAGE = 'generate_image';

    public function modality(): AiModality
    {
        return match ($this) {
            self::GENERATE_IMAGE => AiModality::IMAGE,
            default => AiModality::TEXT,
        };
    }

    /**
     * Créditos por defecto de la operación (coste interno estimado).
     */
    public function defaultCredits(): int
    {
        return match ($this) {
            self::GENERATE_POST => 5,
            self::GENERATE_IDEAS => 3,
            self::IMPROVE_TEXT => 2,
            self::ADAPT_VARIANT => 2,
            self::SUGGEST_REPLY => 2,
            self::GENERATE_IMAGE => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GENERATE_POST => 'Generar publicación',
            self::GENERATE_IDEAS => 'Generar ideas',
            self::IMPROVE_TEXT => 'Mejorar texto',
            self::ADAPT_VARIANT => 'Adaptar por red',
            self::SUGGEST_REPLY => 'Sugerir respuesta',
            self::GENERATE_IMAGE => 'Generar imagen',
        };
    }
}
