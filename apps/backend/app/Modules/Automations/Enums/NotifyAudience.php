<?php

declare(strict_types=1);

namespace App\Modules\Automations\Enums;

use App\Modules\AccessControl\Permissions\Permission;

/**
 * A quién avisa la acción "Avisar al equipo" de una automatización. Cada
 * audiencia se define por un permiso (y el acceso a la marca del evento).
 */
enum NotifyAudience: string
{
    case MANAGERS = 'managers';
    case APPROVERS = 'approvers';
    case PUBLISHERS = 'publishers';
    case TEAM = 'team';

    public function label(): string
    {
        return match ($this) {
            self::MANAGERS => 'Responsables (gestionan automatizaciones)',
            self::APPROVERS => 'Aprobadores de contenido',
            self::PUBLISHERS => 'Quienes programan y publican',
            self::TEAM => 'Todo el equipo de la marca',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::MANAGERS => Permission::AUTOMATIONS_VIEW,
            self::APPROVERS => Permission::CONTENT_APPROVE,
            self::PUBLISHERS => Permission::CONTENT_SCHEDULE,
            self::TEAM => Permission::BRANDS_VIEW,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
