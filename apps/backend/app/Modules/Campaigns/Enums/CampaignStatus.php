<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

enum CampaignStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::ACTIVE => 'Activa',
            self::COMPLETED => 'Completada',
            self::ARCHIVED => 'Archivada',
        };
    }
}
