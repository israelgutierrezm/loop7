<?php

declare(strict_types=1);

namespace App\Modules\Brands\Enums;

enum BrandStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activa',
            self::ARCHIVED => 'Archivada',
        };
    }
}
