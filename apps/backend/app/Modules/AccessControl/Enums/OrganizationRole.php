<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Enums;

/**
 * Roles predefinidos dentro de una Organization.
 *
 * SUPERADMIN NO está aquí: es un rol de plataforma modelado con
 * users.is_platform_admin, deliberadamente fuera del RBAC del cliente
 * (ver docs/18_DECISIONES_TECNICAS.md #8).
 */
enum OrganizationRole: string
{
    case OWNER = 'OWNER';
    case ADMIN = 'ADMIN';
    case MANAGER = 'MANAGER';
    case APPROVER = 'APPROVER';
    case PUBLISHER = 'PUBLISHER';
    case CONTENT_CREATOR = 'CONTENT_CREATOR';
    case ANALYST = 'ANALYST';
    case BILLING = 'BILLING';
    case VIEWER = 'VIEWER';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Propietario',
            self::ADMIN => 'Administrador',
            self::MANAGER => 'Gerente',
            self::APPROVER => 'Aprobador',
            self::PUBLISHER => 'Publicador',
            self::CONTENT_CREATOR => 'Creador de contenido',
            self::ANALYST => 'Analista',
            self::BILLING => 'Facturación',
            self::VIEWER => 'Observador',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
