<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Exceptions;

/**
 * La cuenta no existe o la red no deja leerla (privada, personal, pocos seguidores…).
 * El mensaje es apto para mostrar.
 */
class CompetitorNotFoundException extends CompetitorSourceException
{
}
