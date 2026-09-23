<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Cuenta externa que autorizó la conexión (id estable + nombre legible). El id
 * permite reconectar sin duplicar y atender solicitudes de borrado de datos.
 */
final class RemoteAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
