<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Proveedor con API versionada que SUPERADMIN puede fijar (Graph API de Meta,
 * cabecera LinkedIn-Version…): los proveedores retiran versiones y cambiarla
 * no debe exigir un despliegue. `key` es la clave del ajuste en la
 * configuración del proveedor y `pattern`, la regex PHP de su formato.
 */
interface HasApiVersion
{
    /**
     * @return array{key: string, label: string, default: string, pattern: string, example: string, hint: string}
     */
    public function apiVersionSetting(): array;
}
