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

    public function description(): string
    {
        return match ($this) {
            self::OWNER => 'Control total: facturación, equipo y eliminar o transferir la organización.',
            self::ADMIN => 'Todo salvo eliminar o transferir la organización.',
            self::MANAGER => 'Gestiona marcas, redes, contenido, campañas, automatizaciones y parte del equipo.',
            self::APPROVER => 'Revisa y aprueba el contenido.',
            self::PUBLISHER => 'Programa y publica el contenido aprobado.',
            self::CONTENT_CREATOR => 'Crea contenido, lo envía a revisión y usa la IA.',
            self::ANALYST => 'Consulta y exporta la analítica.',
            self::BILLING => 'Gestiona el plan, los pagos y las facturas.',
            self::VIEWER => 'Solo lectura.',
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
