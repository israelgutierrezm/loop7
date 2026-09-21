<?php

declare(strict_types=1);

namespace App\Modules\Content\Enums;

/**
 * Estados del ciclo de vida de contenido (docs/12).
 */
enum ContentStatus: string
{
    case IDEA = 'idea';
    case DRAFT = 'draft';
    case IN_REVIEW = 'in_review';
    case CHANGES_REQUESTED = 'changes_requested';
    case APPROVED = 'approved';
    case SCHEDULED = 'scheduled';
    case PUBLISHING = 'publishing';
    case PUBLISHED = 'published';
    case PARTIAL = 'partial';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::IDEA => 'Idea',
            self::DRAFT => 'Borrador',
            self::IN_REVIEW => 'En revisión',
            self::CHANGES_REQUESTED => 'Cambios solicitados',
            self::APPROVED => 'Aprobado',
            self::SCHEDULED => 'Programado',
            self::PUBLISHING => 'Publicando',
            self::PUBLISHED => 'Publicado',
            self::PARTIAL => 'Parcial',
            self::FAILED => 'Fallido',
            self::CANCELLED => 'Cancelado',
            self::EXPIRED => 'Expirado',
        };
    }

    /**
     * ¿Es editable el contenido en este estado?
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::IDEA, self::DRAFT, self::CHANGES_REQUESTED], true);
    }
}
