<?php

declare(strict_types=1);

namespace App\Modules\Brands\Enums;

enum BrandStatus: string
{
    // Una marca se elimina (lógicamente, con BrandDeleted); no hay archivado.
    case ACTIVE = 'active';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activa',
        };
    }
}
